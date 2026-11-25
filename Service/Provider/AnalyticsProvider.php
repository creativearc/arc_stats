<?php

namespace CreativeArc\ArcStats\Service\Provider;

/**
 * One analytics backend. Every method returns the same SHAPE regardless of
 * provider, but the numbers are not interchangeable: Fathom "visitors" and
 * GA4 "users" are counted differently, as are the two bounce rates. That is
 * why labels() exists — widgets print the provider's own vocabulary instead
 * of pretending the metrics are the same thing.
 *
 * Dates are Y-m-d, inclusive, in the site's timezone.
 */
interface AnalyticsProvider
{
    public function name(): string;

    /**
     * Display labels keyed by the normalised metric names below.
     */
    public function labels(): array;

    /**
     * ['visitors' => int, 'pageviews' => int, 'sessions' => int,
     *  'avg_duration' => float seconds, 'bounce_rate' => float 0-100|null]
     */
    public function summary(string $from, string $to): array;

    /**
     * [['path' => string, 'pageviews' => int, 'visitors' => int], ...]
     */
    public function pages(string $from, string $to, int $limit): array;

    /**
     * [['source' => string, 'sessions' => int, 'visitors' => int], ...]
     */
    public function sources(string $from, string $to, int $limit): array;

    public function dashboardUrl(): string;
}
