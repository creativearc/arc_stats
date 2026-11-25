<?php

namespace CreativeArc\ArcStats\Service\Widget;

use CreativeArc\ArcStats\Service\Stats;
use ExpressionEngine\Addons\Pro\Model\Dashboard\DashboardWidget;
use ExpressionEngine\Addons\Pro\Service\Dashboard;

/**
 * Base for every arc_stats dashboard widget.
 *
 * What it does, and why, given how Pro renders widgets
 * (Addons/pro/Model/Dashboard/DashboardWidget::generateHtml):
 *
 * - Pro wraps the widget in `catch (\Throwable $e) {}` and prints nothing, so
 *   an uncaught API error would make the widget silently vanish. Every
 *   failure is caught here and rendered as a visible message instead.
 * - AbstractDashboardWidget::getHtml() hides a widget whose content is ''
 *   outside Edit Layout. That is used deliberately: widgets for a service
 *   that is switched off return '' and disappear, so a Fathom-only site is
 *   not cluttered with empty GA4 boxes. In Edit Layout they say why instead.
 * - The one exception is a completely unconfigured add-on: then the widgets
 *   with $setupPrompt show a "not set up" message, or nobody would find out
 *   the add-on needs configuring.
 * - The parent constructor calls getRightHead() BEFORE getContent(), so the
 *   API data is memoised in data() and shared by both.
 */
abstract class StatsWidget extends Dashboard\AbstractDashboardWidget implements Dashboard\DashboardWidgetInterface
{
    public $width = 'half';

    /** Show "Arc Stats is not set up" when nothing at all is configured. */
    protected bool $setupPrompt = false;

    private static bool $styled = false;

    private bool $editMode = false;
    private ?Stats $stats = null;
    private bool $fetched = false;
    private ?array $data = null;
    private ?string $setupError = null;

    abstract protected function titleKey(): string;

    /**
     * Return null when this widget's service is switched off; the result of a
     * Stats call otherwise.
     */
    abstract protected function fetch(Stats $stats): ?array;

    abstract protected function render(array $data): string;

    /**
     * Explains, in Edit Layout only, why the widget is empty.
     */
    abstract protected function offMessage(): string;

    /**
     * Which Settings::ROLE_SOURCES entry governs who may see this widget.
     * Deliberately not abstract: a new abstract method is a class-declaration
     * fatal for any subclass deployed before it, which blanks the whole
     * dashboard if a deploy is ever partial.
     */
    protected function source(): string
    {
        return '';
    }

    /**
     * Prepended to the title, e.g. "Google Search Console · ".
     */
    protected function titlePrefix(): string
    {
        return '';
    }

    /**
     * Appended to the title, e.g. " · Fathom Analytics".
     */
    protected function titleSuffix(): string
    {
        return '';
    }

    public function __construct(DashboardWidget $widgetObject, $edit_mode, $enabled)
    {
        // Must be set before parent::__construct, which calls getContent().
        $this->editMode = (bool) $edit_mode;

        parent::__construct($widgetObject, $edit_mode, $enabled);
    }

    public function getTitle()
    {
        ee()->lang->loadfile('arc_stats');

        return $this->logo() . htmlspecialchars($this->titlePrefix() . lang($this->titleKey()) . $this->titleSuffix());
    }

    /**
     * The add-on icon, inline before the title. Pro prints the title unescaped
     * (pro:dashboard/widget), so markup works there. A data URI rather than a
     * URL: the add-on folder sits outside the web root.
     */
    private function logo(): string
    {
        static $src = null;

        if ($src === null) {
            $svg = @file_get_contents(dirname(__DIR__, 2) . '/icon.svg');
            $src = $svg ? 'data:image/svg+xml;base64,' . base64_encode($svg) : '';
        }

        return $src === ''
            ? ''
            : '<img src="' . $src . '" alt="" width="20" height="20" style="vertical-align:-4px;margin-right:.45em;border-radius:4px">';
    }

    public function getRightHead()
    {
        $data = $this->data();

        if (empty($data['ok']) || empty($data['url'])) {
            return '';
        }

        $url = $this->stats()->linkFor($this->source(), $data['url']);

        if ($url === '') {
            return '';
        }

        return '<a class="button button--default button--small" href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">'
            . htmlspecialchars($data['provider'] ?? '') . ' ↗</a>';
    }

    public function getContent()
    {
        try {
            if ($this->setupError !== null) {
                return $this->message('Arc Stats could not load: ' . $this->setupError, true);
            }

            if (!$this->allowed()) {
                // Plain text, no settings link: this member can't change it.
                return $this->editMode
                    ? $this->style() . '<div class="arc-stats__msg">' . htmlspecialchars(lang('as_role_hidden')) . '</div>'
                    : '';
            }

            if (!$this->stats()->isConfigured()) {
                return $this->setupPrompt || $this->editMode ? $this->message(lang('as_not_configured')) : '';
            }

            $data = $this->data();

            if ($data === null) {
                return $this->editMode ? $this->message($this->offMessage()) : '';
            }

            if (empty($data['ok'])) {
                return $this->message($data['error'] ?? 'Unknown error.', true);
            }

            return $this->style() . $this->render($data);
        } catch (\Throwable $e) {
            return $this->message($e->getMessage(), true);
        }
    }

    protected function view(string $name, array $vars): string
    {
        return ee('View')->make('arc_stats:widgets/' . $name)->render($vars);
    }

    private function stats(): Stats
    {
        return $this->stats ??= new Stats();
    }

    private function data(): ?array
    {
        if (!$this->fetched) {
            $this->fetched = true;

            try {
                // allowed() first: no API call for a widget this member can't see.
                $this->data = $this->allowed() && $this->stats()->isConfigured() ? $this->fetch($this->stats()) : null;
            } catch (\Throwable $e) {
                // e.g. the settings table is missing after a partial install.
                $this->setupError = $e->getMessage();
            }
        }

        return $this->data;
    }

    private function allowed(): bool
    {
        return $this->source() === '' || $this->stats()->canView($this->source());
    }

    private function message(string $text, bool $isError = false): string
    {
        $settings = ee('CP/URL')->make('addons/settings/arc_stats');

        return $this->style() . '<div class="arc-stats__msg' . ($isError ? ' arc-stats__msg--error' : '') . '">'
            . htmlspecialchars($text)
            . ' <a href="' . $settings . '">' . lang('as_configure') . '</a></div>';
    }

    /**
     * Shared CSS, printed once per page however many widgets are on it.
     */
    private function style(): string
    {
        if (self::$styled) {
            return '';
        }
        self::$styled = true;

        return ee('View')->make('arc_stats:widgets/_style')->render([]);
    }
}
