<?php

namespace CreativeArc\ArcStats\Service\Widget;

use CreativeArc\ArcStats\Service\Stats;

/**
 * A widget bound to one analytics provider.
 *
 * Pro loads widget classes by FILE NAME — widgets/fathom_pages.php must
 * declare {Namespace}\Widgets\Fathom_pages (ucfirst of the file name, see
 * DashboardWidget::generateHtml) — so each provider × widget pair needs its
 * own file. Those files only set $provider; everything else lives in the
 * Traffic/Pages/Sources subclasses of this.
 */
abstract class AnalyticsWidget extends StatsWidget
{
    /** A key of Stats::PROVIDERS. */
    protected string $provider = '';

    protected function source(): string
    {
        return $this->provider;
    }

    protected function titleSuffix(): string
    {
        return ' · ' . (Stats::PROVIDERS[$this->provider] ?? $this->provider);
    }

    protected function offMessage(): string
    {
        return 'Hidden: ' . (Stats::PROVIDERS[$this->provider] ?? $this->provider) . ' is switched off in settings.';
    }
}
