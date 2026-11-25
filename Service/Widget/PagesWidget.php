<?php

namespace CreativeArc\ArcStats\Service\Widget;

use CreativeArc\ArcStats\Service\Format;
use CreativeArc\ArcStats\Service\Stats;

abstract class PagesWidget extends AnalyticsWidget
{
    protected function titleKey(): string
    {
        return 'as_widget_pages';
    }

    protected function fetch(Stats $stats): ?array
    {
        return $stats->hasProvider($this->provider) ? $stats->pages($this->provider, 10) : null;
    }

    protected function render(array $d): string
    {
        return $this->view('table', [
            'caption' => lang('as_last_30'),
            'columns' => ['Page', $d['labels']['pageviews'], $d['labels']['visitors']],
            'rows'    => array_map(fn ($r) => [$r['path'], Format::number($r['pageviews']), Format::number($r['visitors'])], $d['rows']),
            'weights' => array_column($d['rows'], 'pageviews'),
        ]);
    }
}
