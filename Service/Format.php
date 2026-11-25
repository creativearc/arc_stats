<?php

namespace CreativeArc\ArcStats\Service;

class Format
{
    public static function number($n): string
    {
        $n = (float) $n;

        if ($n >= 1000000) {
            return round($n / 1000000, 1) . 'M';
        }
        if ($n >= 10000) {
            return round($n / 1000, 1) . 'k';
        }

        return number_format($n);
    }

    public static function duration($seconds): string
    {
        $seconds = (int) round((float) $seconds);

        return $seconds < 60 ? $seconds . 's' : intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
    }

    public static function percent(?float $value): string
    {
        return $value === null ? '—' : round($value, 1) . '%';
    }

    public static function position(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1);
    }

    /**
     * Period-over-period change. $lowerIsBetter flips the colour, not the sign
     * (bounce rate, search position).
     *
     * @return array{text: string, class: string}
     */
    public static function change($current, $previous, bool $lowerIsBetter = false): array
    {
        if ($current === null || $previous === null || (float) $previous == 0.0) {
            return ['text' => '', 'class' => 'flat'];
        }

        $pct = ((float) $current - (float) $previous) / (float) $previous * 100;

        if (abs($pct) < 0.5) {
            return ['text' => '±0%', 'class' => 'flat'];
        }

        $good = $lowerIsBetter ? $pct < 0 : $pct > 0;

        return [
            'text'  => ($pct > 0 ? '▲ ' : '▼ ') . round(abs($pct)) . '%',
            'class' => $good ? 'good' : 'bad',
        ];
    }

    /**
     * Change in a rate, in percentage points ("▼ 11.3 pts"), as Fathom and GA4
     * show it. A relative change on a rate (26% from 37.6% = "▼ 30%") reads as
     * a different number from the one on the analytics dashboard.
     *
     * @return array{text: string, class: string}
     */
    public static function pointChange(?float $current, ?float $previous, bool $lowerIsBetter = false): array
    {
        if ($current === null || $previous === null) {
            return ['text' => '', 'class' => 'flat'];
        }

        $pts = round($current - $previous, 1);

        if ($pts == 0.0) {
            return ['text' => '±0 pts', 'class' => 'flat'];
        }

        $good = $lowerIsBetter ? $pts < 0 : $pts > 0;

        return [
            'text'  => ($pts > 0 ? '▲ ' : '▼ ') . abs($pts) . ' pts',
            'class' => $good ? 'good' : 'bad',
        ];
    }

    /**
     * Signed absolute change: "▲ 12", "▼ 3", "–".
     */
    public static function delta(int $n): string
    {
        if ($n === 0) {
            return '–';
        }

        return ($n > 0 ? '▲ ' : '▼ ') . self::number(abs($n));
    }

    /**
     * "Oct 22 – Nov 20"
     */
    public static function range(array $range): string
    {
        return date('M j', strtotime($range[0])) . ' – ' . date('M j', strtotime($range[1]));
    }
}
