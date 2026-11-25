<?php

namespace CreativeArc\ArcStats\Service\Widget;

use CreativeArc\ArcStats\Service\Format;
use CreativeArc\ArcStats\Service\Stats;

abstract class SourcesWidget extends AnalyticsWidget
{
    protected function titleKey(): string
    {
        return 'as_widget_sources';
    }

    protected function fetch(Stats $stats): ?array
    {
        return $stats->hasProvider($this->provider) ? $stats->sources($this->provider, 10) : null;
    }

    protected function render(array $d): string
    {
        // No sessions label (Fathom): its visits equal uniques, so show one column.
        $withSessions = isset($d['labels']['sessions']);

        return $this->view('table', [
            'caption' => lang('as_last_30'),
            'columns' => $withSessions
                ? ['Source', $d['labels']['sessions'], $d['labels']['visitors']]
                : ['Source', $d['labels']['visitors']],
            'rows'    => array_map(fn ($r) => $withSessions
                ? [$r['source'], Format::number($r['sessions']), Format::number($r['visitors'])]
                : [$r['source'], Format::number($r['visitors'])], $d['rows']),
            'weights' => array_column($d['rows'], 'sessions'),
        ]);
    }
}
