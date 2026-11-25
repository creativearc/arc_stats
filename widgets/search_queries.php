<?php

namespace CreativeArc\ArcStats\Widgets;

use CreativeArc\ArcStats\Service\Format;
use CreativeArc\ArcStats\Service\Stats;
use CreativeArc\ArcStats\Service\Widget\SearchWidget;

/**
 * Rising and falling search queries: biggest click gains and losses against
 * the previous 30 days. Class name is dictated by Pro: ucfirst() of the file name.
 */
class Search_queries extends SearchWidget
{
    protected function titleKey(): string
    {
        return 'as_widget_search_queries';
    }

    protected function fetch(Stats $stats): ?array
    {
        return $stats->hasSearchConsole() ? $stats->searchQueries(5) : null;
    }

    protected function render(array $d): string
    {
        $section = fn (array $rows, string $caption, string $empty) => $this->view('table', [
            'caption' => $caption,
            'empty'   => $empty,
            'columns' => ['Query', 'Clicks', 'Change', 'Impr. change'],
            'rows'    => array_map(fn ($r) => [
                $r['query'],
                Format::number($r['clicks']),
                $r['new'] ? 'new' : Format::delta($r['delta']),
                Format::delta($r['imp_delta']),
            ], $rows),
            'weights' => array_map(fn ($r) => abs($r['delta']), $rows),
        ]);

        return '<p class="arc-stats__range">' . htmlspecialchars(Format::range($d['range']) . ' ' . lang('as_vs_previous')) . '</p>'
            . $section($d['rising'], lang('as_rising'), 'No queries gained clicks or impressions.')
            . '<div class="arc-stats__gap"></div>'
            . $section($d['falling'], lang('as_falling'), 'No queries lost clicks or impressions.');
    }
}
