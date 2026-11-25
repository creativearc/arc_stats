<?php

namespace CreativeArc\ArcStats\Service\Google;

class SearchConsole
{
    private GoogleClient $client;
    private string $property;

    public function __construct(GoogleClient $client, string $property)
    {
        $this->client   = $client;
        $this->property = self::normalizeProperty($property);
    }

    /**
     * Totals for the period: clicks, impressions, ctr (0-100), position.
     */
    public function totals(string $from, string $to): array
    {
        $rows = $this->query(['startDate' => $from, 'endDate' => $to]);
        $row  = $rows[0] ?? [];

        return [
            'clicks'      => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr'         => isset($row['ctr']) ? (float) $row['ctr'] * 100 : null,
            'position'    => isset($row['position']) ? (float) $row['position'] : null,
        ];
    }

    /**
     * Top queries by clicks, then impressions.
     */
    public function queries(string $from, string $to, int $limit = 10): array
    {
        $rows = $this->query([
            'startDate'  => $from,
            'endDate'    => $to,
            'dimensions' => ['query'],
            'rowLimit'   => $limit,
        ]);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'query'       => $row['keys'][0] ?? '',
                'clicks'      => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'position'    => (float) ($row['position'] ?? 0),
            ];
        }

        // GSC orders by clicks already, but a long tail of 0-click rows comes
        // back in arbitrary order; impressions is the useful tiebreak.
        usort($out, fn ($a, $b) => [$b['clicks'], $b['impressions']] <=> [$a['clicks'], $a['impressions']]);

        return $out;
    }

    /**
     * Queries whose clicks moved most between two periods.
     *
     * Ranked by click change, with impression change as the tiebreak, so a
     * low-traffic site where most queries get 0-2 clicks still shows which
     * terms are gaining or losing visibility.
     *
     * Each period fetches its top $pool queries. A query outside one period's
     * pool reads as 0 there, which would label it "new" (or gone) when it
     * merely ranked lower. So `new` is only claimed when the previous period
     * came back shorter than $pool, i.e. it was the complete list.
     *
     * @return array{rising: array, falling: array}
     */
    public function movers(array $current, array $previous, int $limit = 5, int $pool = 500): array
    {
        $now    = array_column($this->queries($current[0], $current[1], $pool), null, 'query');
        $before = array_column($this->queries($previous[0], $previous[1], $pool), null, 'query');

        $beforeComplete = count($before) < $pool;

        $rows = [];
        foreach (array_keys($now + $before) as $query) {
            $c = $now[$query] ?? ['clicks' => 0, 'impressions' => 0, 'position' => 0];
            $p = $before[$query] ?? ['clicks' => 0, 'impressions' => 0, 'position' => 0];

            $rows[] = [
                'query'       => (string) $query,
                'clicks'      => $c['clicks'],
                'delta'       => $c['clicks'] - $p['clicks'],
                'impressions' => $c['impressions'],
                'imp_delta'   => $c['impressions'] - $p['impressions'],
                'new'         => $beforeComplete && !isset($before[$query]) && $c['clicks'] > 0,
            ];
        }

        $rising = array_filter($rows, fn ($r) => $r['delta'] > 0 || ($r['delta'] === 0 && $r['imp_delta'] > 0));
        usort($rising, fn ($a, $b) => [$b['delta'], $b['imp_delta']] <=> [$a['delta'], $a['imp_delta']]);

        $falling = array_filter($rows, fn ($r) => $r['delta'] < 0 || ($r['delta'] === 0 && $r['imp_delta'] < 0));
        usort($falling, fn ($a, $b) => [$a['delta'], $a['imp_delta']] <=> [$b['delta'], $b['imp_delta']]);

        return [
            'rising'  => array_slice($rising, 0, $limit),
            'falling' => array_slice($falling, 0, $limit),
        ];
    }

    /**
     * Sitemaps submitted for this property: [['path' => url, 'errors' => n, 'warnings' => n, 'lastDownloaded' => iso], ...]
     */
    public function sitemaps(): array
    {
        $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($this->property) . '/sitemaps';

        return $this->client->getJson($url)['sitemap'] ?? [];
    }

    /**
     * URL Inspection API — one URL per call, ~2,000 calls/day and 600/min per
     * property. Returns the raw `inspectionResult` object.
     */
    public function inspect(string $url): array
    {
        $data = $this->client->postJson('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
            'inspectionUrl' => $url,
            'siteUrl'       => $this->property,
            'languageCode'  => 'en-US',
        ]);

        return $data['inspectionResult'] ?? [];
    }

    public function property(): string
    {
        return $this->property;
    }

    /**
     * Whether a URL belongs to this property. Inspection rejects any that
     * do not, so sitemap URLs are filtered through this first.
     *
     * Domain property (sc-domain:example.com) covers every scheme and
     * subdomain; a URL-prefix property covers only URLs starting with it.
     */
    public function owns(string $url): bool
    {
        if (str_starts_with($this->property, 'sc-domain:')) {
            $domain = strtolower(substr($this->property, strlen('sc-domain:')));
            $host   = strtolower((string) parse_url($url, PHP_URL_HOST));

            return $host === $domain || str_ends_with($host, '.' . $domain);
        }

        return str_starts_with($url, $this->property);
    }

    /**
     * Where to look for sitemaps when none are submitted: the property root.
     */
    public function rootUrl(): string
    {
        return str_starts_with($this->property, 'sc-domain:')
            ? 'https://' . substr($this->property, strlen('sc-domain:')) . '/'
            : $this->property;
    }

    public function dashboardUrl(): string
    {
        return 'https://search.google.com/search-console/performance/search-analytics?resource_id='
            . rawurlencode($this->property);
    }

    private function query(array $body): array
    {
        $url = 'https://searchconsole.googleapis.com/webmasters/v3/sites/'
             . rawurlencode($this->property) . '/searchAnalytics/query';

        return $this->client->postJson($url, $body)['rows'] ?? [];
    }

    /**
     * https://example.com   → https://example.com/   (URL-prefix property)
     * sc-domain:example.com → unchanged              (domain property)
     * example.com           → sc-domain:example.com
     */
    public static function normalizeProperty(string $value): string
    {
        $value = trim($value);

        if ($value === '' || str_starts_with($value, 'sc-domain:')) {
            return $value;
        }

        if (preg_match('#^https?://#i', $value)) {
            return rtrim($value, '/') . '/';
        }

        return 'sc-domain:' . rtrim($value, '/');
    }
}
