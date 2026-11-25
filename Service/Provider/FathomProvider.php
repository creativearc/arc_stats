<?php

namespace CreativeArc\ArcStats\Service\Provider;

/**
 * Fathom Analytics aggregations API. Reports a 429's Retry-After
 * in the error message.
 */
class FathomProvider implements AnalyticsProvider
{
    const BASE_URL = 'https://api.usefathom.com/v1';

    const AGGREGATES = 'visits,uniques,pageviews,avg_duration,bounce_rate';

    private string $apiKey;
    private string $siteId;
    private string $timezone;

    public function __construct(string $apiKey, string $siteId, string $timezone = 'UTC')
    {
        if ($apiKey === '' || $siteId === '') {
            throw new \InvalidArgumentException('Fathom needs both an API key and a site ID.');
        }

        $this->apiKey   = $apiKey;
        $this->siteId   = $siteId;
        $this->timezone = $timezone ?: 'UTC';
    }

    public function name(): string
    {
        return 'Fathom';
    }

    public function labels(): array
    {
        return [
            'visitors'     => 'Visitors',
            'pageviews'    => 'Pageviews',
            // No 'sessions' label: the API's `visits` equals `uniques` (verified
            // on live data 2026-11-24), so the tile would repeat Visitors.
            'avg_duration' => 'Avg. time on site',
            'bounce_rate'  => 'Bounce rate',
        ];
    }

    public function summary(string $from, string $to): array
    {
        $data = $this->aggregate($from, $to);
        $row  = isset($data[0]) ? $data[0] : $data;

        return [
            'visitors'     => (int) ($row['uniques'] ?? 0),
            'pageviews'    => (int) ($row['pageviews'] ?? 0),
            'sessions'     => (int) ($row['visits'] ?? 0),
            'avg_duration' => (float) ($row['avg_duration'] ?? 0),
            'bounce_rate'  => self::percent($row['bounce_rate'] ?? null),
        ];
    }

    public function pages(string $from, string $to, int $limit): array
    {
        $rows = $this->aggregate($from, $to, [
            'field_grouping' => 'pathname',
            'sort_by'        => 'pageviews:desc',
            'limit'          => $limit,
        ]);

        return array_map(fn ($r) => [
            'path'      => (string) ($r['pathname'] ?? ''),
            'pageviews' => (int) ($r['pageviews'] ?? 0),
            'visitors'  => (int) ($r['uniques'] ?? 0),
        ], $rows);
    }

    public function sources(string $from, string $to, int $limit): array
    {
        $rows = $this->aggregate($from, $to, [
            'field_grouping' => 'referrer_hostname',
            'sort_by'        => 'visits:desc',
            'limit'          => $limit,
        ]);

        return array_map(fn ($r) => [
            'source'   => self::hostname((string) ($r['referrer_hostname'] ?? '')),
            'sessions' => (int) ($r['visits'] ?? 0),
            'visitors' => (int) ($r['uniques'] ?? 0),
        ], $rows);
    }

    public function dashboardUrl(): string
    {
        return 'https://app.usefathom.com/?site=' . rawurlencode($this->siteId);
    }

    private function aggregate(string $from, string $to, array $extra = []): array
    {
        return $this->request('/aggregations', $extra + [
            'entity'     => 'pageview',
            'entity_id'  => $this->siteId,
            'date_from'  => $from . ' 00:00:00',
            'date_to'    => $to . ' 23:59:59',
            'timezone'   => $this->timezone,
            'aggregates' => self::AGGREGATES,
        ]);
    }

    /**
     * Normalise bounce_rate to 0-100. Fathom's documented scale is 0-100, but
     * accept a 0-1 fraction too. (A genuine bounce rate
     * of 1% or less would be misread as a fraction, which is an acceptable miss.)
     */
    private static function percent($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (float) $value;

        return $value <= 1 ? $value * 100 : $value;
    }

    /**
     * Fathom returns referrer_hostname with its scheme (https://www.google.com).
     * An empty hostname is direct traffic.
     */
    private static function hostname(string $value): string
    {
        $value = preg_replace('#^https?://#i', '', trim($value));

        return $value === '' ? '(direct)' : rtrim($value, '/');
    }

    private function request(string $path, array $params): array
    {
        $headers = [];

        $ch = curl_init(self::BASE_URL . $path . '?' . http_build_query($params));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new \RuntimeException('cURL error: ' . $curlErr);
        }

        if ($httpCode !== 200) {
            $detail = '';
            if ($httpCode === 429 && isset($headers['retry-after'])) {
                $detail = ' [retry after ' . $headers['retry-after'] . 's]';
            }

            throw new \RuntimeException('Fathom ' . $httpCode . ': ' . substr((string) $response, 0, 300) . $detail);
        }

        $decoded = json_decode((string) $response, true);

        return is_array($decoded) ? $decoded : [];
    }
}
