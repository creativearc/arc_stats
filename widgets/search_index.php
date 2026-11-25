<?php

namespace CreativeArc\ArcStats\Widgets;

use CreativeArc\ArcStats\Service\Format;
use CreativeArc\ArcStats\Service\Stats;
use CreativeArc\ArcStats\Service\Widget\SearchWidget;

/**
 * Index Status — Google's verdict on each sitemap page, from the table the
 * arc_stats:index command fills. Class name is dictated by Pro: ucfirst() of
 * the file name.
 */
class Search_index extends SearchWidget
{
    protected function titleKey(): string
    {
        return 'as_widget_search_index';
    }

    protected function fetch(Stats $stats): ?array
    {
        return $stats->hasSearchConsole() ? $stats->indexStatus() : null;
    }

    protected function render(array $d): string
    {
        if ($d['total'] === 0) {
            return '<div class="arc-stats__msg">No pages checked yet. Run '
                . '<code>php system/ee/eecli.php arc_stats:index</code>, then schedule it daily.</div>';
        }

        $none = ['text' => '', 'class' => 'flat'];

        $tiles = [
            ['label' => 'Indexed',     'value' => Format::number($d['indexed']),    'change' => $none],
            ['label' => 'Not indexed', 'value' => Format::number($d['notIndexed']), 'change' => $d['notIndexed'] ? ['text' => 'see below', 'class' => 'bad'] : $none],
        ];
        if ($d['unchecked']) {
            $tiles[] = ['label' => 'Not checked yet', 'value' => Format::number($d['unchecked']), 'change' => $none];
        }

        $checked = $d['total'] - $d['unchecked'];
        $caption = "Checked {$checked} of {$d['total']} sitemap pages";
        if ($d['oldest']) {
            $days     = (int) floor((time() - $d['oldest']) / 86400);
            $caption .= ' · oldest check ' . ($days < 1 ? 'today' : ($days === 1 ? '1 day ago' : "{$days} days ago"));
        }

        return $this->view('tiles', ['tiles' => $tiles, 'caption' => $caption])
            . $this->view('index', ['reasons' => $d['reasons'], 'rich' => $d['rich']]);
    }
}
