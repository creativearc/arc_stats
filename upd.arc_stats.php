<?php

/**
 * Dashboard widgets are NOT registered here. EE's Addons controller calls
 * updateDashboardWidgets() after install AND after update, which scans
 * widgets/*.php, inserts rows for new files and deletes rows for files that
 * are gone. Renaming a widget file therefore needs a version bump.
 */
class Arc_stats_upd
{
    public $version = '1.0.0';

    public function install(): bool
    {
        ee('Model')->make('Module', [
            'module_name'        => 'Arc_stats',
            'module_version'     => $this->version,
            'has_cp_backend'     => 'y',
            'has_publish_fields' => 'n',
        ])->save();

        $table = $this->table();

        // One row per MSM site. Secrets (fathom_api_key, google_credentials)
        // are stored ee('Encrypt')-encoded.
        ee()->db->query("
            CREATE TABLE IF NOT EXISTS `{$table}` (
                `site_id`            INT UNSIGNED NOT NULL,
                `fathom_enabled`     CHAR(1)      NOT NULL DEFAULT 'n',
                `ga4_enabled`        CHAR(1)      NOT NULL DEFAULT 'n',
                `fathom_api_key`     TEXT         NOT NULL,
                `fathom_site_id`     VARCHAR(50)  NOT NULL DEFAULT '',
                `fathom_share_url`   VARCHAR(500) NOT NULL DEFAULT '',
                `ga4_property_id`    VARCHAR(50)  NOT NULL DEFAULT '',
                `google_credentials` TEXT         NOT NULL,
                `gsc_property`       VARCHAR(255) NOT NULL DEFAULT '',
                `fathom_roles`       VARCHAR(255) NOT NULL DEFAULT '',
                `ga4_roles`          VARCHAR(255) NOT NULL DEFAULT '',
                `gsc_roles`          VARCHAR(255) NOT NULL DEFAULT '',
                `updated`            INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`site_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        \CreativeArc\ArcStats\Service\IndexStatus::createTable();

        return true;
    }

    public function uninstall(): bool
    {
        ee('Model')->get('Module')->filter('module_name', 'Arc_stats')->delete();
        ee()->db->query('DROP TABLE IF EXISTS `' . $this->table() . '`');
        ee()->db->query('DROP TABLE IF EXISTS `' . ee()->db->dbprefix(\CreativeArc\ArcStats\Service\IndexStatus::TABLE) . '`');

        return true;
    }

    public function update($current = ''): bool
    {
        if ($current === $this->version) {
            return false;
        }

        $table = $this->table();

        // 0.2.0: one `provider` choice became two independent switches, so a
        // site can show Fathom and GA4 side by side.
        if (version_compare($current, '0.2.0', '<') && ee()->db->field_exists('provider', 'arc_stats_settings')) {
            ee()->db->query("ALTER TABLE `{$table}`
                ADD COLUMN `fathom_enabled` CHAR(1) NOT NULL DEFAULT 'n' AFTER `site_id`,
                ADD COLUMN `ga4_enabled`    CHAR(1) NOT NULL DEFAULT 'n' AFTER `fathom_enabled`");
            ee()->db->query("UPDATE `{$table}` SET
                `fathom_enabled` = IF(`provider` = 'fathom', 'y', 'n'),
                `ga4_enabled`    = IF(`provider` = 'ga4', 'y', 'n')");
            ee()->db->query("ALTER TABLE `{$table}` DROP COLUMN `provider`");
        }

        // 0.6.0: Fathom share link for people without a Fathom login.
        if (!ee()->db->field_exists('fathom_share_url', 'arc_stats_settings')) {
            ee()->db->query("ALTER TABLE `{$table}` ADD COLUMN `fathom_share_url` VARCHAR(500) NOT NULL DEFAULT '' AFTER `fathom_site_id`");
        }

        // 0.5.0: per-source role restrictions. '' = no restriction, so existing
        // sites behave exactly as before until someone ticks a role.
        foreach (['fathom_roles', 'ga4_roles', 'gsc_roles'] as $column) {
            if (!ee()->db->field_exists($column, 'arc_stats_settings')) {
                ee()->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` VARCHAR(255) NOT NULL DEFAULT '' AFTER `gsc_property`");
            }
        }

        // 0.4.0: Index Status results. CREATE IF NOT EXISTS, and the command
        // also calls it, so this is safe to repeat.
        \CreativeArc\ArcStats\Service\IndexStatus::createTable();

        return true;
    }

    private function table(): string
    {
        return ee()->db->dbprefix('arc_stats_settings');
    }
}
