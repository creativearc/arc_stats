<?php

namespace CreativeArc\ArcStats\Service\Provider;

use CreativeArc\ArcStats\Service\Google\GoogleClient;

/**
 * Google Analytics Data API v1beta, runReport.
 * Dates are interpreted in the GA4 property's own reporting timezone.
 */
class Ga4Provider implements AnalyticsProvider
{
    private GoogleClient $client;
    private string $propertyId;

    public function __construct(GoogleClient $client, string $propertyId)
    {
        // Accept "properties/123" or "123".
        $propertyId = preg_replace('#^properties/#', '', trim($propertyId));

        if (!ctype_digit($propertyId)) {
            throw new \InvalidArgumentException('GA4 property ID must be numeric (not the G-… measurement ID).');
        }

        $this->client     = $client;
        $this->propertyId = $propertyId;
    }

    public function name(): string
    {
        return 'Google Analytics';
    }

    public function labels(): array
    {
        return [
            'visitors'     => 'Users',
            'pageviews'    => 'Views',
            'sessions'     => 'Sessions',
            'avg_duration' => 'Avg. session duration',
            'bounce_rate'  => 'Bounce rate',
        ];
    }

    public function summary(string $from, string $to): array
    {
        $rows = $this->report($from, $to, [], ['totalUsers', 'screenPageViews', 'sessions', 'averageSessionDuration', 'bounceRate']);
        $m    = $rows[0]['metrics'] ?? [];

        return [
            'visitors'     => (int) ($m[0] ?? 0),
            'pageviews'    => (int) ($m[1] ?? 0),
            'sessions'     => (int) ($m[2] ?? 0),
            'avg_duration' => (float) ($m[3] ?? 0),
            // GA4 bounce rate is a 0-1 fraction, defined as 1 - engagement rate.
            'bounce_rate'  => isset($m[4]) ? (float) $m[4] * 100 : null,
        ];
    }

    public function pages(string $from, string $to, int $limit): array
    {
        $rows = $this->report($from, $to, ['pagePath'], ['screenPageViews', 'totalUsers'], $limit);

        return array_map(fn ($r) => [
            'path'      => $r['dimensions'][0] ?? '',
            'pageviews' => (int) ($r['metrics'][0] ?? 0),
            'visitors'  => (int) ($r['metrics'][1] ?? 0),
        ], $rows);
    }

    public function sources(string $from, string $to, int $limit): array
    {
        $rows = $this->report($from, $to, ['sessionSource'], ['sessions', 'totalUsers'], $limit);

        return array_map(fn ($r) => [
            'source'   => $r['dimensions'][0] ?? '',
            'sessions' => (int) ($r['metrics'][0] ?? 0),
            'visitors' => (int) ($r['metrics'][1] ?? 0),
        ], $rows);
    }

    public function dashboardUrl(): string
    {
        return 'https://analytics.google.com/analytics/web/#/p' . $this->propertyId . '/reports/intelligenthome';
    }

    /**
     * Runs a report ordered by the first metric, descending. Returns rows as
     * ['dimensions' => [string...], 'metrics' => [string...]].
     */
    private function report(string $from, string $to, array $dimensions, array $metrics, int $limit = 0): array
    {
        $body = [
            'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
            'metrics'    => array_map(fn ($m) => ['name' => $m], $metrics),
        ];

        if ($dimensions) {
            $body['dimensions'] = array_map(fn ($d) => ['name' => $d], $dimensions);
            $body['orderBys']   = [['metric' => ['metricName' => $metrics[0]], 'desc' => true]];
        }

        if ($limit > 0) {
            $body['limit'] = $limit;
        }

        $data = $this->client->postJson(
            'https://analyticsdata.googleapis.com/v1beta/properties/' . $this->propertyId . ':runReport',
            $body
        );

        return array_map(fn ($row) => [
            'dimensions' => array_column($row['dimensionValues'] ?? [], 'value'),
            'metrics'    => array_column($row['metricValues'] ?? [], 'value'),
        ], $data['rows'] ?? []);
    }
}
