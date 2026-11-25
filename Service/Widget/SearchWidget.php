<?php

namespace CreativeArc\ArcStats\Service\Widget;

/**
 * A Google Search Console widget. Independent of the analytics providers —
 * works with Fathom, GA4, both, or neither.
 */
abstract class SearchWidget extends StatsWidget
{
    protected function source(): string
    {
        return 'gsc';
    }

    protected function titlePrefix(): string
    {
        return 'Google Search Console · ';
    }

    protected function offMessage(): string
    {
        return 'Hidden: no Search Console property is set.';
    }
}
