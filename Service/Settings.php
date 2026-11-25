<?php

namespace CreativeArc\ArcStats\Service;

/**
 * Per-site settings row in exp_arc_stats_settings. Secrets are encrypted at
 * rest with ee('Encrypt'); everything returned from get() is plaintext.
 */
class Settings
{
    const TABLE = 'arc_stats_settings';

    const SECRETS = ['fathom_api_key', 'google_credentials'];

    const DEFAULTS = [
        'fathom_enabled'     => 'n',
        'ga4_enabled'        => 'n',
        'fathom_api_key'     => '',
        'fathom_site_id'     => '',
        // Fathom shared-dashboard URL for people without a Fathom login.
        'fathom_share_url'   => '',
        'ga4_property_id'    => '',
        'google_credentials' => '',
        'gsc_property'       => '',
        // Role IDs allowed to see each source's widgets, '|'-separated.
        // '' = every role that can see the dashboard widgets at all.
        'fathom_roles'       => '',
        'ga4_roles'          => '',
        'gsc_roles'          => '',
        'updated'            => 0,
    ];

    /** Widget sources that can be restricted by role: settings key prefix => label. */
    const ROLE_SOURCES = [
        'fathom' => 'Fathom Analytics',
        'ga4'    => 'Google Analytics',
        'gsc'    => 'Search Console',
    ];

    /**
     * @return int[] role IDs allowed for this source; [] means no restriction
     */
    public static function roleIds(array $settings, string $source): array
    {
        $raw = (string) ($settings[$source . '_roles'] ?? '');

        return $raw === '' ? [] : array_values(array_filter(array_map('intval', explode('|', $raw))));
    }

    public static function siteId(): int
    {
        return (int) ee()->config->item('site_id');
    }

    public static function get(?int $siteId = null): array
    {
        $row = ee()->db->get_where(self::TABLE, ['site_id' => $siteId ?? self::siteId()])->row_array();

        // row_array() returns [] on no match, never null.
        $settings = array_merge(self::DEFAULTS, array_intersect_key($row, self::DEFAULTS));

        foreach (self::SECRETS as $key) {
            if ($settings[$key] !== '') {
                $decoded = ee('Encrypt')->decode($settings[$key]);
                // A changed encryption key decodes to false/garbage; treat as unset
                // rather than feeding junk to an API.
                $settings[$key] = is_string($decoded) ? $decoded : '';
            }
        }

        return $settings;
    }

    /**
     * Saves the given keys. A secret passed as '' keeps its stored value, so
     * the settings form can leave password/key fields blank.
     */
    public static function save(array $data, ?int $siteId = null): void
    {
        $siteId  = $siteId ?? self::siteId();
        $current = self::get($siteId);
        $row     = [];

        foreach (self::DEFAULTS as $key => $default) {
            $value = array_key_exists($key, $data) ? trim((string) $data[$key]) : $current[$key];

            if (in_array($key, self::SECRETS, true)) {
                if ($value === '') {
                    $value = $current[$key];
                }
                $value = $value === '' ? '' : ee('Encrypt')->encode($value);
            }

            $row[$key] = $value;
        }

        $row['updated'] = time();

        $exists = ee()->db->where('site_id', $siteId)->count_all_results(self::TABLE) > 0;

        if ($exists) {
            ee()->db->where('site_id', $siteId)->update(self::TABLE, $row);
        } else {
            ee()->db->insert(self::TABLE, $row + ['site_id' => $siteId]);
        }
    }

    /**
     * Decoded service-account JSON, or null if absent/invalid.
     */
    public static function serviceAccount(array $settings): ?array
    {
        if ($settings['google_credentials'] === '') {
            return null;
        }

        $json = json_decode($settings['google_credentials'], true);

        return is_array($json) && !empty($json['client_email']) && !empty($json['private_key']) ? $json : null;
    }
}
