<?php

namespace CreativeArc\ArcStats\Service;

use CreativeArc\ArcStats\Service\Google\GoogleClient;
use CreativeArc\ArcStats\Service\Google\SearchConsole;
use CreativeArc\ArcStats\Service\Provider\AnalyticsProvider;
use CreativeArc\ArcStats\Service\Provider\FathomProvider;
use CreativeArc\ArcStats\Service\Provider\Ga4Provider;

/**
 * What the widgets call. Owns the reporting period, the cache, and turning
 * API failures into a displayable error instead of an exception.
 *
 * Fathom and GA4 are independent: either, both or neither can be on. They
 * are never merged or compared in one number — each gets its own widgets —
 * because the two count visitors differently.
 *
 * Why the cache matters: the dashboard renders on every CP visit, for every
 * editor, and each widget is a separate API call. Uncached, a busy morning of
 * logins is enough to trip Fathom's 429s.
 */
class Stats
{
    /** Provider key => display name. Keys match the `{key}_enabled` settings columns. */
    const PROVIDERS = [
        'fathom' => 'Fathom Analytics',
        'ga4'    => 'Google Analytics',
    ];

    const DAYS = 30;

    const TTL      = 3600;   // a period ending yesterday only changes once a day; 1h keeps "today's" view honest
    const TTL_FAIL = 300;    // remember failures briefly so a dead API isn't re-hit on every page load

    /**
     * GSC data for the last ~2 days is incomplete, and Google's own UI ends its
     * default range there too. Analytics data is complete through yesterday.
     */
    const GSC_LAG_DAYS = 3;

    private array $settings;

    public function __construct(?array $settings = null)
    {
        $this->settings = $settings ?? Settings::get();
    }

    public function isConfigured(): bool
    {
        return $this->enabledProviders() !== [] || $this->hasSearchConsole();
    }

    public function hasProvider(string $key): bool
    {
        return isset(self::PROVIDERS[$key]) && ($this->settings[$key . '_enabled'] ?? 'n') === 'y';
    }

    /**
     * @return string[] provider keys, in PROVIDERS order
     */
    public function enabledProviders(): array
    {
        return array_values(array_filter(array_keys(self::PROVIDERS), [$this, 'hasProvider']));
    }

    public function hasSearchConsole(): bool
    {
        return $this->settings['gsc_property'] !== '';
    }

    /**
     * The "open in …" button link. Fathom's app link only works for someone
     * logged in to the Fathom account that owns the site, and no EE role tells
     * us who that is: on a client install the client is a Super Admin with no
     * access to our Fathom. So Fathom gets the shared-dashboard link when one
     * is set and no button otherwise (a link to a login wall is worse than
     * none). Google sources (GA4, Search Console) get no button at all: a
     * client Super Admin doesn't necessarily have access to those properties
     * either.
     */
    public function linkFor(string $source, string $appUrl): string
    {
        if ($source !== 'fathom') {
            return '';
        }

        return $this->settings['fathom_share_url'] ?? '';
    }

    /**
     * May the logged-in member see this source's widgets? Super Admins always
     * can (they configure it). An empty role list means no restriction beyond
     * Pro's own check, which already requires add-on access to Arc Stats.
     *
     * @param string $source a key of Settings::ROLE_SOURCES
     */
    public function canView(string $source): bool
    {
        if (ee('Permission')->isSuperAdmin()) {
            return true;
        }

        $allowed = Settings::roleIds($this->settings, $source);

        if ($allowed === []) {
            return true;
        }

        $member = ee()->session->getMember();

        return $member !== null
            && array_intersect($allowed, array_map('intval', $member->getAllRoles()->pluck('role_id'))) !== [];
    }

    /**
     * Current and previous periods: [from, to] Y-m-d, both ending yesterday
     * (or GSC_LAG_DAYS ago for Search Console), in the site timezone.
     */
    public function periods(int $lagDays = 1): array
    {
        $tz  = new \DateTimeZone(ee()->config->item('default_site_timezone') ?: 'UTC');
        $end = (new \DateTimeImmutable('today', $tz))->modify("-{$lagDays} day");

        $start     = $end->modify('-' . (self::DAYS - 1) . ' days');
        $prevEnd   = $start->modify('-1 day');
        $prevStart = $prevEnd->modify('-' . (self::DAYS - 1) . ' days');

        return [
            'current'  => [$start->format('Y-m-d'), $end->format('Y-m-d')],
            'previous' => [$prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')],
        ];
    }

    /**
     * @return array{ok: bool, error?: string, provider?: string, labels?: array, current?: array, previous?: array, url?: string}
     */
    public function overview(string $key): array
    {
        return $this->cached("{$key}/overview", function () use ($key) {
            $p = $this->provider($key);
            $periods = $this->periods();

            return [
                'provider' => $p->name(),
                'labels'   => $p->labels(),
                'current'  => $p->summary(...$periods['current']),
                'previous' => $p->summary(...$periods['previous']),
                'url'      => $p->dashboardUrl(),
                'range'    => $periods['current'],
            ];
        });
    }

    public function pages(string $key, int $limit = 10): array
    {
        return $this->cached("{$key}/pages/{$limit}", function () use ($key, $limit) {
            $p = $this->provider($key);

            return [
                'provider' => $p->name(),
                'labels'   => $p->labels(),
                'rows'     => $p->pages(...array_merge($this->periods()['current'], [$limit])),
                'url'      => $p->dashboardUrl(),
            ];
        });
    }

    public function sources(string $key, int $limit = 10): array
    {
        return $this->cached("{$key}/sources/{$limit}", function () use ($key, $limit) {
            $p = $this->provider($key);

            return [
                'provider' => $p->name(),
                'labels'   => $p->labels(),
                'rows'     => $p->sources(...array_merge($this->periods()['current'], [$limit])),
                'url'      => $p->dashboardUrl(),
            ];
        });
    }

    public function searchTotals(): array
    {
        return $this->cached('gsc/totals', function () {
            $gsc     = $this->searchConsole();
            $periods = $this->periods(self::GSC_LAG_DAYS);

            return [
                'current'  => $gsc->totals(...$periods['current']),
                'previous' => $gsc->totals(...$periods['previous']),
                'url'      => $gsc->dashboardUrl(),
                'range'    => $periods['current'],
            ];
        });
    }

    public function searchQueries(int $limit = 5): array
    {
        return $this->cached("gsc/movers/{$limit}", function () use ($limit) {
            $gsc     = $this->searchConsole();
            $periods = $this->periods(self::GSC_LAG_DAYS);

            return $gsc->movers($periods['current'], $periods['previous'], $limit) + [
                'url'   => $gsc->dashboardUrl(),
                'range' => $periods['current'],
            ];
        });
    }

    /**
     * Index Status summary, read from exp_arc_stats_index (filled by the
     * arc_stats:index command). Not cached: it is a local query.
     */
    public function indexStatus(): array
    {
        try {
            $property = SearchConsole::normalizeProperty($this->settings['gsc_property']);

            return ['ok' => true] + IndexStatus::summary(Settings::siteId()) + [
                'url' => 'https://search.google.com/search-console/index?resource_id=' . rawurlencode($property),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * For the arc_stats:index command.
     */
    public function indexer(): IndexStatus
    {
        return new IndexStatus($this->searchConsole(), Settings::siteId());
    }

    /**
     * Uncached round-trip against each enabled service, for the settings
     * page. Returns [service name => 'OK — …' | error message].
     */
    public function test(): array
    {
        $results   = [];
        $yesterday = $this->periods()['current'][1];

        foreach ($this->enabledProviders() as $key) {
            try {
                $s = $this->provider($key)->summary($yesterday, $yesterday);
                $results[self::PROVIDERS[$key]] = 'OK — ' . number_format($s['pageviews']) . ' pageviews yesterday';
            } catch (\Throwable $e) {
                $results[self::PROVIDERS[$key]] = $e->getMessage();
            }
        }

        if ($this->hasSearchConsole()) {
            try {
                $day = $this->periods(self::GSC_LAG_DAYS)['current'][1];
                $t   = $this->searchConsole()->totals($day, $day);
                $results['Search Console'] = 'OK — ' . number_format($t['clicks']) . ' clicks on ' . $day;
            } catch (\Throwable $e) {
                $results['Search Console'] = $e->getMessage();
            }
        }

        return $results;
    }

    public static function clearCache(): void
    {
        // A trailing slash deletes the whole namespace.
        ee()->cache->delete('/arc_stats/' . Settings::siteId() . '/', \Cache::GLOBAL_SCOPE);
    }

    public function provider(string $key): AnalyticsProvider
    {
        if (!$this->hasProvider($key)) {
            throw new \RuntimeException((self::PROVIDERS[$key] ?? $key) . ' is not enabled.');
        }

        switch ($key) {
            case 'fathom':
                return new FathomProvider(
                    $this->settings['fathom_api_key'],
                    $this->settings['fathom_site_id'],
                    ee()->config->item('default_site_timezone') ?: 'UTC'
                );

            case 'ga4':
                return new Ga4Provider($this->google(), $this->settings['ga4_property_id']);
        }

        throw new \RuntimeException('Unknown provider: ' . $key);
    }

    private function searchConsole(): SearchConsole
    {
        if (!$this->hasSearchConsole()) {
            throw new \RuntimeException('No Search Console property set.');
        }

        return new SearchConsole($this->google(), $this->settings['gsc_property']);
    }

    private function google(): GoogleClient
    {
        $account = Settings::serviceAccount($this->settings);

        if ($account === null) {
            throw new \RuntimeException('No valid Google service account key saved.');
        }

        return new GoogleClient($account);
    }

    private function cached(string $key, callable $fetch): array
    {
        // Settings fingerprint in the key: changing a property or switching a
        // provider must not serve stale numbers for the next hour.
        $fingerprint = substr(md5(implode('|', [
            $this->settings['fathom_enabled'],
            $this->settings['ga4_enabled'],
            $this->settings['fathom_site_id'],
            $this->settings['ga4_property_id'],
            $this->settings['gsc_property'],
            $this->settings['updated'],
        ])), 0, 10);

        $cacheKey = '/arc_stats/' . Settings::siteId() . '/' . $fingerprint . '/' . $key . '/' . date('Ymd');
        $hit      = ee()->cache->get($cacheKey, \Cache::GLOBAL_SCOPE);

        if (is_array($hit)) {
            return $hit;
        }

        try {
            $result = ['ok' => true] + $fetch();
            ee()->cache->save($cacheKey, $result, self::TTL, \Cache::GLOBAL_SCOPE);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
            ee()->cache->save($cacheKey, $result, self::TTL_FAIL, \Cache::GLOBAL_SCOPE);
        }

        return $result;
    }
}
