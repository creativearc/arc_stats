<?php

namespace CreativeArc\ArcStats\Service\Widget;

use CreativeArc\ArcStats\Service\Format;
use CreativeArc\ArcStats\Service\Stats;

abstract class TrafficWidget extends AnalyticsWidget
{
    public $width = 'full';

    protected bool $setupPrompt = true;

    protected function titleKey(): string
    {
        return 'as_widget_overview';
    }

    protected function fetch(Stats $stats): ?array
    {
        return $stats->hasProvider($this->provider) ? $stats->overview($this->provider) : null;
    }

    protected function render(array $d): string
    {
        $c = $d['current'];
        $p = $d['previous'];
        $l = $d['labels'];

        $tiles = [];
        // A provider omits a metric's label to hide its tile (Fathom has no
        // separate sessions count: its `visits` equals `uniques`).
        foreach (['visitors', 'pageviews', 'sessions'] as $key) {
            if (isset($l[$key])) {
                $tiles[] = ['label' => $l[$key], 'value' => Format::number($c[$key]), 'change' => Format::change($c[$key], $p[$key])];
            }
        }
        $tiles[] = ['label' => $l['avg_duration'], 'value' => Format::duration($c['avg_duration']), 'change' => Format::change($c['avg_duration'], $p['avg_duration'])];
        $tiles[] = ['label' => $l['bounce_rate'], 'value' => Format::percent($c['bounce_rate']), 'change' => Format::pointChange($c['bounce_rate'], $p['bounce_rate'], true)];

        return $this->view('tiles', [
            'tiles'   => $tiles,
            'caption' => Format::range($d['range']) . ' ' . lang('as_vs_previous'),
        ]);
    }
}
