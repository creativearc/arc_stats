<?php

namespace CreativeArc\ArcStats\Commands;

use CreativeArc\ArcStats\Service\IndexStatus;
use CreativeArc\ArcStats\Service\Stats;
use ExpressionEngine\Cli\Cli;

/**
 * Daily job behind the Index Status widget. See Service/IndexStatus.php for
 * why this is a batch job and not a live API call.
 */
class IndexCommand extends Cli
{
    public $name        = 'Arc Stats Index Status';
    public $description = 'Checks sitemap pages with the Search Console URL Inspection API.';
    public $summary     = 'Refreshes the URL list from the sitemaps, then inspects the least-recently-checked pages.';
    public $usage       = 'php system/ee/eecli.php arc_stats:index [--limit=200] [--dry-run] [--url=https://…]';

    public $commandOptions = [
        'limit,l:' => 'Pages to inspect this run (default 200; Google allows ~2,000/day per property).',
        'dry-run'  => 'Read the sitemaps and report what would change; inspect nothing, write nothing.',
        'url,u:'   => 'Inspect this one URL and print Google\'s raw response. Writes nothing.',
        'verbose'  => 'Print every inspected URL, not just problems.',
    ];

    public function handle()
    {
        ee()->lang->loadfile('arc_stats');

        $stats = new Stats();

        if (!$stats->hasSearchConsole()) {
            $this->output->outln('<<red>>No Search Console property is set in Arc Stats settings.<<reset>>');
            exit(1);
        }

        $indexer = $stats->indexer();

        if ($url = $this->option('--url')) {
            try {
                $this->output->outln(json_encode($indexer->inspectRaw($url), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } catch (\Throwable $e) {
                $this->output->outln('<<red>>' . $e->getMessage() . '<<reset>>');
                exit(1);
            }

            return;
        }

        $dryRun = (bool) $this->option('--dry-run');
        $limit  = max(1, (int) ($this->option('--limit') ?: 200));

        // Dry runs write nothing, so only writing runs need the lock.
        if ($dryRun) {
            $this->runIndex($indexer, true, $limit);
            return;
        }

        if (!$indexer->lock()) {
            $this->output->outln('<<yellow>>Another arc_stats:index run is in progress for this site; exiting.<<reset>>');
            exit(4);
        }

        // exit() skips finally blocks, so take the code and exit after unlocking.
        try {
            $code = $this->runIndex($indexer, false, $limit);
        } finally {
            $indexer->unlock();
        }

        if ($code) {
            exit($code);
        }
    }

    /**
     * @return int exit code (0 = success)
     */
    private function runIndex(IndexStatus $indexer, bool $dryRun, int $limit): int
    {
        if (!$dryRun) {
            IndexStatus::createTable();
        }

        $this->output->outln('<<dim>>Reading sitemaps…<<reset>>');
        $sync = $indexer->refreshUrls($dryRun);

        foreach ($sync['sitemaps'] as $sitemap) {
            $this->output->outln("  {$sitemap}");
        }
        foreach ($sync['warnings'] as $warning) {
            $this->output->outln("<<yellow>>  {$warning}<<reset>>");
        }
        $this->output->outln(sprintf(
            '%d URLs in sitemaps · %d new · %d no longer listed%s',
            $sync['found'],
            $sync['added'],
            $sync['removed'],
            $dryRun ? ' (dry run: nothing written)' : ''
        ));

        if ($dryRun) {
            return 0;
        }

        $this->output->outln("<<dim>>Inspecting up to {$limit} pages…<<reset>>");
        $verbose = (bool) $this->option('--verbose');

        $result = $indexer->inspectBatch($limit, function ($url, $row, $error) use ($verbose) {
            if ($error !== null) {
                $this->output->outln("<<red>>  ✗ {$url} — {$error}<<reset>>");
            } elseif (!in_array($row['verdict'], ['PASS', 'PARTIAL'], true)) {
                $this->output->outln("<<yellow>>  ○ {$url} — {$row['coverage_state']}<<reset>>");
            } elseif ($verbose) {
                $this->output->outln("  ✓ {$url}");
            }
        });

        $this->output->outln(sprintf('Inspected %d pages, %d failed.', $result['inspected'], $result['failed']));

        if ($result['stopped']) {
            $this->output->outln('<<yellow>>Stopped early — quota: ' . $result['stopped'] . '<<reset>>');
            return 3;
        }

        // Every inspection failing almost always means access, not the pages.
        if ($result['inspected'] > 0 && $result['failed'] === $result['inspected']) {
            return 1;
        }

        return 0;
    }
}
