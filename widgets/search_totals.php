<?php

namespace CreativeArc\ArcStats\Widgets;

use CreativeArc\ArcStats\Service\Format;
use CreativeArc\ArcStats\Service\Stats;
use CreativeArc\ArcStats\Service\Widget\SearchWidget;

/**
 * Class name is dictated by Pro: ucfirst() of this file's name.
 */
class Search_totals extends SearchWidget
{
    protected function titleKey(): string
    {
        return 'as_widget_search_totals';
    }

    protected function fetch(Stats $stats): ?array
    {
        return $stats->hasSearchConsole() ? $stats->searchTotals() : null;
    }

    protected function render(array $d): string
    {
        $c = $d['current'];
        $p = $d['previous'];

        return $this->view('tiles', [
            // Ends GSC_LAG_DAYS ago: Google's last couple of days are incomplete.
            'caption' => Format::range($d['range']) . ' ' . lang('as_vs_previous'),
            'tiles'   => [
                ['label' => 'Clicks',        'value' => Format::number($c['clicks']),      'change' => Format::change($c['clicks'], $p['clicks'])],
                ['label' => 'Impressions',   'value' => Format::number($c['impressions']), 'change' => Format::change($c['impressions'], $p['impressions'])],
                ['label' => 'Avg. position', 'value' => Format::position($c['position']),  'change' => Format::change($c['position'], $p['position'], true)],
            ],
        ]);
    }
}
