<?php

namespace CreativeArc\ArcStats\Service;

use CreativeArc\ArcStats\Service\Google\SearchConsole;

/**
 * Index Status: Google's verdict on each page the site's sitemaps list.
 *
 * Search Console's API has no endpoint for the Page indexing / Issues
 * reports. The URL Inspection API answers the same question one URL at a
 * time, capped at ~2,000 calls a day per property — far too slow and too
 * rationed to call while the dashboard loads. So a daily CLI job
 * (arc_stats:index) inspects the least-recently-checked pages in batches and
 * stores each verdict in exp_arc_stats_index; the widget only reads the table.
 *
 * The URL list comes from the site's sitemaps rather than from EE entries:
 * entry URLs depend on each site's templates, which an add-on cannot know,
 * and the sitemap is exactly "the pages I asked Google to index".
 */
class IndexStatus
{
    const TABLE = 'arc_stats_index';

    const MAX_URLS          = 10000;
    const MAX_SITEMAP_DEPTH = 3;

    /** Stay well under the 600/min per-property limit. */
    const MIN_INTERVAL_US = 150000;

    private SearchConsole $gsc;
    private int $siteId;

    public function __construct(SearchConsole $gsc, int $siteId)
    {
        $this->gsc    = $gsc;
        $this->siteId = $siteId;
    }

    public static function createTable(): void
    {
        $table = ee()->db->dbprefix(self::TABLE);

        ee()->db->query("
            CREATE TABLE IF NOT EXISTS `{$table}` (
                `site_id`          INT UNSIGNED  NOT NULL,
                `url_hash`         CHAR(32)      NOT NULL,
                `url`              VARCHAR(2048) NOT NULL,
                `verdict`          VARCHAR(30)   NOT NULL DEFAULT '',
                `coverage_state`   VARCHAR(255)  NOT NULL DEFAULT '',
                `indexing_state`   VARCHAR(50)   NOT NULL DEFAULT '',
                `robots_state`     VARCHAR(30)   NOT NULL DEFAULT '',
                `fetch_state`      VARCHAR(50)   NOT NULL DEFAULT '',
                `google_canonical` VARCHAR(2048) NOT NULL DEFAULT '',
                `user_canonical`   VARCHAR(2048) NOT NULL DEFAULT '',
                `last_crawl`       INT UNSIGNED  NOT NULL DEFAULT 0,
                `rich_verdict`     VARCHAR(30)   NOT NULL DEFAULT '',
                `rich_issues`      TEXT          NULL,
                `result_link`      VARCHAR(2048) NOT NULL DEFAULT '',
                `error`            TEXT          NULL,
                `inspected`        INT UNSIGNED  NOT NULL DEFAULT 0,
                `added`            INT UNSIGNED  NOT NULL DEFAULT 0,
                PRIMARY KEY (`site_id`, `url_hash`),
                KEY `next` (`site_id`, `inspected`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // ── Run lock ──────────────────────────────────────────────────────────

    /**
     * Take the per-site lock that stops two writing runs (the daily cron and
     * a manual run) overlapping — they would inspect the same rows and spend
     * the URL Inspection quota twice.
     *
     * A MySQL named lock rather than a lock file: it belongs to this DB
     * connection, so it is released if the process dies, and it holds across
     * web nodes. The name includes the database so two installs sharing one
     * MySQL server do not block each other.
     */
    public function lock(): bool
    {
        // NULL (an error) counts as not acquired.
        return (int) ee()->db->query('SELECT GET_LOCK(' . self::LOCK_NAME . ', 0) AS got', [$this->siteId])->row('got') === 1;
    }

    public function unlock(): void
    {
        ee()->db->query('SELECT RELEASE_LOCK(' . self::LOCK_NAME . ')', [$this->siteId]);
    }

    /** SQL for the lock name; GET_LOCK names are capped at 64 characters. */
    const LOCK_NAME = "CONCAT('arc_stats_index_', MD5(CONCAT(DATABASE(), ':', ?)))";

    // ── URL list ──────────────────────────────────────────────────────────

    /**
     * Sync the table with the sitemaps: add new URLs (uninspected, so they go
     * to the front of the queue), drop URLs no longer listed.
     *
     * If no URL at all is found, nothing is deleted — a sitemap that failed to
     * fetch must not wipe the whole history.
     *
     * @return array{sitemaps: string[], found: int, added: int, removed: int, warnings: string[]}
     */
    public function refreshUrls(bool $dryRun = false): array
    {
        $warnings = [];
        $sitemaps = $this->discoverSitemaps($warnings);

        $urls = [];
        $seen = [];
        foreach ($sitemaps as $sitemap) {
            $this->collect($sitemap, $urls, $seen, 0, $warnings);
        }

        $outside = 0;
        foreach ($urls as $hash => $url) {
            if (!$this->gsc->owns($url)) {
                unset($urls[$hash]);
                $outside++;
            }
        }
        if ($outside) {
            $warnings[] = "{$outside} sitemap URLs are outside the Search Console property and were skipped.";
        }

        // A dry run must work before the table exists (it never creates it).
        $existing = !ee()->db->table_exists(self::TABLE) ? [] : array_column(
            ee()->db->select('url_hash')->where('site_id', $this->siteId)->get(self::TABLE)->result_array(),
            'url_hash',
            'url_hash'
        );

        $new  = array_diff_key($urls, $existing);
        $gone = $urls ? array_diff_key($existing, $urls) : [];

        if (!$urls) {
            $warnings[] = 'No URLs found in any sitemap; the existing list was left untouched.';
        }

        if (!$dryRun) {
            $now = time();
            foreach (array_chunk($new, 500, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $hash => $url) {
                    $rows[] = ['site_id' => $this->siteId, 'url_hash' => $hash, 'url' => $url, 'added' => $now, 'rich_issues' => '', 'error' => ''];
                }
                ee()->db->insert_batch(self::TABLE, $rows);
            }

            foreach (array_chunk(array_keys($gone), 500) as $chunk) {
                ee()->db->where('site_id', $this->siteId)->where_in('url_hash', $chunk)->delete(self::TABLE);
            }
        }

        return [
            'sitemaps' => $sitemaps,
            'found'    => count($urls),
            'added'    => count($new),
            'removed'  => count($gone),
            'warnings' => $warnings,
        ];
    }

    /**
     * Submitted sitemaps (Search Console) + robots.txt `Sitemap:` lines,
     * falling back to /sitemap.xml at the property root.
     */
    private function discoverSitemaps(array &$warnings): array
    {
        $found = [];

        try {
            foreach ($this->gsc->sitemaps() as $sitemap) {
                if (!empty($sitemap['path'])) {
                    $found[] = $sitemap['path'];
                }
            }
        } catch (\Throwable $e) {
            $warnings[] = 'Could not list submitted sitemaps: ' . $e->getMessage();
        }

        $robots = $this->fetch($this->gsc->rootUrl() . 'robots.txt');
        if ($robots !== null && preg_match_all('/^\s*sitemap:\s*(\S+)/im', $robots, $m)) {
            array_push($found, ...$m[1]);
        }

        if (!$found) {
            $found[] = $this->gsc->rootUrl() . 'sitemap.xml';
        }

        return array_values(array_unique($found));
    }

    /**
     * Read one sitemap into $urls (hash => url), following sitemap indexes.
     */
    private function collect(string $sitemapUrl, array &$urls, array &$seen, int $depth, array &$warnings): void
    {
        if ($depth > self::MAX_SITEMAP_DEPTH || isset($seen[$sitemapUrl]) || count($urls) >= self::MAX_URLS) {
            return;
        }
        $seen[$sitemapUrl] = true;

        $body = $this->fetch($sitemapUrl);
        if ($body === null) {
            $warnings[] = "Could not fetch {$sitemapUrl}";
            return;
        }

        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            $warnings[] = "{$sitemapUrl} is not valid XML";
            return;
        }

        // Namespaces vary between generators; match on local element names.
        $root = strtolower($xml->getName());

        foreach ($xml->children() as $entry) {
            $loc = '';
            foreach ($entry->children() as $child) {
                if (strtolower($child->getName()) === 'loc') {
                    $loc = trim((string) $child);
                }
            }
            if ($loc === '') {
                continue;
            }

            if ($root === 'sitemapindex') {
                $this->collect($loc, $urls, $seen, $depth + 1, $warnings);
            } elseif (count($urls) < self::MAX_URLS) {
                $urls[md5($loc)] = $loc;
            }
        }
    }

    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'ArcStats sitemap reader',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            return null;
        }

        // .xml.gz sitemaps
        if (strncmp($body, "\x1f\x8b", 2) === 0) {
            $body = @gzdecode($body);
        }

        return is_string($body) ? $body : null;
    }

    // ── Inspection ────────────────────────────────────────────────────────

    /**
     * One live inspection, unparsed and unsaved — for debugging (--url).
     */
    public function inspectRaw(string $url): array
    {
        return $this->gsc->inspect($url);
    }

    /**
     * Inspect the $limit least-recently-checked URLs (never-checked first).
     * Stops early on a quota error rather than burning the rest of the batch.
     *
     * @param callable|null $progress fn(string $url, array $row, ?string $error)
     * @return array{inspected: int, failed: int, stopped: ?string}
     */
    public function inspectBatch(int $limit, ?callable $progress = null): array
    {
        $rows = ee()->db->select('url_hash, url')
            ->where('site_id', $this->siteId)
            ->order_by('inspected', 'asc')
            ->order_by('added', 'asc')
            ->limit($limit)
            ->get(self::TABLE)
            ->result_array();

        $done = $failed = 0;
        $stopped = null;

        foreach ($rows as $row) {
            $started = microtime(true);

            try {
                $fields = self::parse($this->gsc->inspect($row['url']));
                $error  = null;
            } catch (\Throwable $e) {
                $error  = $e->getMessage();
                $fields = ['error' => $error];
                $failed++;
            }

            // Quota exhausted: leave this row's previous result alone and stop.
            if ($error !== null && (strpos($error, ' 429') !== false || stripos($error, 'quota') !== false)) {
                $stopped = $error;
                break;
            }

            $fields['inspected'] = time();
            ee()->db->where('site_id', $this->siteId)->where('url_hash', $row['url_hash'])->update(self::TABLE, $fields);
            $done++;

            if ($progress) {
                $progress($row['url'], $fields, $error);
            }

            $elapsed = (int) ((microtime(true) - $started) * 1000000);
            if ($elapsed < self::MIN_INTERVAL_US) {
                usleep(self::MIN_INTERVAL_US - $elapsed);
            }
        }

        return ['inspected' => $done, 'failed' => $failed, 'stopped' => $stopped];
    }

    /**
     * Flatten an inspectionResult into table columns.
     */
    public static function parse(array $result): array
    {
        $index = $result['indexStatusResult'] ?? [];
        $rich  = $result['richResultsResult'] ?? [];

        $issues = [];
        foreach ($rich['detectedItems'] ?? [] as $type) {
            foreach ($type['items'] ?? [] as $item) {
                foreach ($item['issues'] ?? [] as $issue) {
                    $issues[] = trim(($type['richResultType'] ?? 'Rich result') . ': ' . ($issue['issueMessage'] ?? ''))
                        . (isset($issue['severity']) ? ' (' . strtolower($issue['severity']) . ')' : '');
                }
            }
        }

        return [
            'verdict'          => (string) ($index['verdict'] ?? ''),
            'coverage_state'   => mb_substr((string) ($index['coverageState'] ?? ''), 0, 255),
            'indexing_state'   => (string) ($index['indexingState'] ?? ''),
            'robots_state'     => (string) ($index['robotsTxtState'] ?? ''),
            'fetch_state'      => (string) ($index['pageFetchState'] ?? ''),
            'google_canonical' => (string) ($index['googleCanonical'] ?? ''),
            'user_canonical'   => (string) ($index['userCanonical'] ?? ''),
            'last_crawl'       => !empty($index['lastCrawlTime']) ? (int) strtotime($index['lastCrawlTime']) : 0,
            'rich_verdict'     => (string) ($rich['verdict'] ?? ''),
            'rich_issues'      => $issues ? json_encode(array_values(array_unique($issues))) : '',
            'result_link'      => (string) ($result['inspectionResultLink'] ?? ''),
            'error'            => '',
        ];
    }

    // ── Read side (widget) ────────────────────────────────────────────────

    /**
     * Counts and grouped problems, from the table only — no API calls.
     */
    public static function summary(int $siteId, int $examples = 3): array
    {
        $rows = ee()->db->select('url, verdict, coverage_state, rich_verdict, rich_issues, result_link, error, inspected')
            ->where('site_id', $siteId)
            ->get(self::TABLE)
            ->result_array();

        $s = [
            'total'      => count($rows),
            'unchecked'  => 0,
            'indexed'    => 0,
            'notIndexed' => 0,
            'errors'     => 0,
            'reasons'    => [],   // coverage_state => ['count' => n, 'pages' => [...]]
            'rich'       => [],   // issue text => ['count' => n, 'pages' => [...]]
            'oldest'     => 0,
            'newest'     => 0,
        ];

        foreach ($rows as $r) {
            if ((int) $r['inspected'] === 0) {
                $s['unchecked']++;
                continue;
            }

            $s['oldest'] = $s['oldest'] ? min($s['oldest'], (int) $r['inspected']) : (int) $r['inspected'];
            $s['newest'] = max($s['newest'], (int) $r['inspected']);

            $page = ['url' => $r['url'], 'link' => $r['result_link']];

            if ($r['error'] !== '' && $r['error'] !== null) {
                $s['errors']++;
                self::group($s['reasons'], 'Could not inspect: ' . self::shortError($r['error']), $page, $examples);
                continue;
            }

            // PASS = indexed. PARTIAL is Google's "indexed, with warnings".
            if (in_array($r['verdict'], ['PASS', 'PARTIAL'], true)) {
                $s['indexed']++;
            } else {
                $s['notIndexed']++;
                self::group($s['reasons'], $r['coverage_state'] ?: 'Not indexed (no reason given)', $page, $examples);
            }

            foreach (json_decode((string) $r['rich_issues'], true) ?: [] as $issue) {
                self::group($s['rich'], $issue, $page, $examples);
            }
        }

        uasort($s['reasons'], fn ($a, $b) => $b['count'] <=> $a['count']);
        uasort($s['rich'], fn ($a, $b) => $b['count'] <=> $a['count']);

        return $s;
    }

    private static function group(array &$groups, string $key, array $page, int $examples): void
    {
        $groups[$key]['count'] = ($groups[$key]['count'] ?? 0) + 1;
        $groups[$key]['pages'] = $groups[$key]['pages'] ?? [];

        if (count($groups[$key]['pages']) < $examples) {
            $groups[$key]['pages'][] = $page;
        }
    }

    private static function shortError(string $error): string
    {
        return mb_strimwidth(preg_replace('/\s+/', ' ', $error), 0, 90, '…');
    }
}
