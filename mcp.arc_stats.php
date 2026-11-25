<?php

use CreativeArc\ArcStats\Service\Google\SearchConsole;
use CreativeArc\ArcStats\Service\Settings;
use CreativeArc\ArcStats\Service\Stats;

/**
 * /cp/addons/settings/arc_stats — one settings form. Saving clears the cache
 * and runs a live connection test, so a bad key or a missing property grant
 * shows up here rather than as an empty dashboard widget.
 */
class Arc_stats_mcp
{
    public function index(): array
    {
        $baseUrl  = ee('CP/URL')->make('addons/settings/arc_stats');
        $settings = Settings::get();
        $input    = $settings;

        if (ee('Request')->isPost()) {
            $input = [
                'fathom_enabled'     => ee('Request')->post('fathom_enabled') === 'y' ? 'y' : 'n',
                'ga4_enabled'        => ee('Request')->post('ga4_enabled') === 'y' ? 'y' : 'n',
                'fathom_api_key'     => trim((string) ee('Request')->post('fathom_api_key')),
                'fathom_site_id'     => trim((string) ee('Request')->post('fathom_site_id')),
                'fathom_share_url'   => trim((string) ee('Request')->post('fathom_share_url')),
                'ga4_property_id'    => preg_replace('#^properties/#', '', trim((string) ee('Request')->post('ga4_property_id'))),
                'google_credentials' => trim((string) ee('Request')->post('google_credentials')),
                'gsc_property'       => SearchConsole::normalizeProperty((string) ee('Request')->post('gsc_property')),
            ];

            foreach (array_keys(Settings::ROLE_SOURCES) as $source) {
                $input[$source . '_roles'] = $this->postedRoles($source . '_roles');
            }

            $errors = $this->validate($input, $settings);

            if ($errors) {
                ee('CP/Alert')->makeInline('arc-stats')
                    ->asIssue()
                    ->withTitle('Settings not saved')
                    ->addToBody($errors)
                    ->now();
            } else {
                Settings::save($input);
                Stats::clearCache();

                $results = (new Stats())->test();
                $failed  = array_filter($results, fn ($r) => strpos($r, 'OK') !== 0);

                $alert = ee('CP/Alert')->makeInline('arc-stats')
                    ->withTitle(lang('as_saved') . ($failed ? ', but a connection test failed' : ''));
                $failed ? $alert->asWarning() : $alert->asSuccess();

                $lines = [];
                foreach ($results as $service => $result) {
                    $lines[] = $service . ': ' . $result;
                }
                if ($lines) {
                    $alert->addToBody($lines);
                }
                $alert->defer();

                ee()->functions->redirect($baseUrl);
            }
        }

        $account = Settings::serviceAccount($settings);

        $vars = [
            'base_url'              => $baseUrl,
            'alerts_name'           => 'arc-stats',
            'cp_page_title'         => lang('as_settings'),
            'save_btn_text'         => 'btn_save_settings',
            'save_btn_text_working' => 'btn_saving',
            'sections'              => [
                [
                    [
                        'title'  => 'as_fathom_enabled',
                        'desc'   => 'as_fathom_enabled_desc',
                        'fields' => [
                            'fathom_enabled' => [
                                'type'         => 'yes_no',
                                'value'        => $input['fathom_enabled'],
                                'group_toggle' => ['y' => 'fathom'],
                            ],
                        ],
                    ],
                    [
                        'title'  => 'as_fathom_api_key',
                        'desc'   => lang('as_fathom_api_key_desc')
                            . ($settings['fathom_api_key'] !== '' ? '<br>Saved: <code>…' . htmlspecialchars(substr($settings['fathom_api_key'], -4)) . '</code>' : ''),
                        'group'  => 'fathom',
                        'fields' => [
                            'fathom_api_key' => ['type' => 'password', 'value' => ''],
                        ],
                    ],
                    [
                        'title'  => 'as_fathom_site_id',
                        'desc'   => 'as_fathom_site_id_desc',
                        'group'  => 'fathom',
                        'fields' => [
                            'fathom_site_id' => ['type' => 'text', 'value' => $input['fathom_site_id']],
                        ],
                    ],
                    [
                        'title'  => 'as_fathom_share_url',
                        'desc'   => 'as_fathom_share_url_desc',
                        'group'  => 'fathom',
                        'fields' => [
                            'fathom_share_url' => [
                                'type'        => 'text',
                                'value'       => $input['fathom_share_url'],
                                'placeholder' => 'https://app.usefathom.com/share/…',
                            ],
                        ],
                    ],
                    $this->roleField('fathom', $input) + ['group' => 'fathom'],
                    [
                        'title'  => 'as_ga4_enabled',
                        'desc'   => 'as_ga4_enabled_desc',
                        'fields' => [
                            'ga4_enabled' => [
                                'type'         => 'yes_no',
                                'value'        => $input['ga4_enabled'],
                                'group_toggle' => ['y' => 'ga4'],
                            ],
                        ],
                    ],
                    [
                        'title'  => 'as_ga4_property_id',
                        'desc'   => 'as_ga4_property_id_desc',
                        'group'  => 'ga4',
                        'fields' => [
                            'ga4_property_id' => ['type' => 'text', 'value' => $input['ga4_property_id']],
                        ],
                    ],
                    $this->roleField('ga4', $input) + ['group' => 'ga4'],
                ],
                'as_google_setup' => [
                    [
                        'title'  => 'as_google_credentials',
                        'desc'   => lang('as_google_credentials_desc')
                            . ($account ? '<br>Saved: <code>' . htmlspecialchars($account['client_email']) . '</code> — grant this address read access in GA4 and Search Console.' : ''),
                        'fields' => [
                            'google_credentials' => [
                                'type'        => 'textarea',
                                'value'       => '',
                                'placeholder' => '{"type": "service_account", ...}',
                                'rows'        => 4,
                            ],
                        ],
                    ],
                    [
                        'title'  => 'as_gsc_property',
                        'desc'   => 'as_gsc_property_desc',
                        'fields' => [
                            'gsc_property' => ['type' => 'text', 'value' => $input['gsc_property']],
                        ],
                    ],
                    $this->roleField('gsc', $input),
                ],
            ],
        ];

        return [
            'heading'    => lang('as_settings'),
            'breadcrumb' => [$baseUrl->compile() => lang('arc_stats_module_name')],
            'body'       => ee('View')->make('ee:_shared/form')->render($vars),
        ];
    }

    /**
     * "Who can see the X widgets" checkboxes. Super Admins are left out (they
     * always see everything), as are Banned/Guests/Pending, which can't reach
     * the CP. Nothing ticked = no restriction, so existing sites keep working.
     */
    private function roleField(string $source, array $input): array
    {
        $choices = ee('Model')->get('Role')
            ->filter('role_id', 'NOT IN', [1, 2, 3, 4])
            ->order('name')
            ->all()
            ->getDictionary('role_id', 'name');

        return [
            'title'  => sprintf(lang('as_roles'), Settings::ROLE_SOURCES[$source]),
            'desc'   => 'as_roles_desc',
            'fields' => [
                $source . '_roles' => [
                    'type'    => 'checkbox',
                    'choices' => $choices,
                    'value'   => Settings::roleIds($input, $source),
                    'no_results' => ['text' => 'No roles besides Super Admin.'],
                ],
            ],
        ];
    }

    private function postedRoles(string $name): string
    {
        $posted = ee('Request')->post($name);
        $ids    = array_filter(array_map('intval', is_array($posted) ? $posted : []));
        sort($ids);

        return implode('|', array_unique($ids));
    }

    private function validate(array $input, array $saved): array
    {
        $errors = [];

        if ($input['google_credentials'] !== '' && Settings::serviceAccount(['google_credentials' => $input['google_credentials']]) === null) {
            $errors[] = 'The Google key is not a service account JSON file (needs client_email and private_key).';
        }

        $hasGoogle = $input['google_credentials'] !== '' || $saved['google_credentials'] !== '';

        // Each provider is validated only when switched on, so its fields can be
        // left half-filled while it is off.
        if ($input['fathom_enabled'] === 'y') {
            if ($input['fathom_site_id'] === '') {
                $errors[] = 'Fathom needs a site ID.';
            }
            if ($input['fathom_api_key'] === '' && $saved['fathom_api_key'] === '') {
                $errors[] = 'Fathom needs an API key.';
            }
            if ($input['fathom_share_url'] !== '' && strpos($input['fathom_share_url'], 'https://app.usefathom.com/share/') !== 0) {
                $errors[] = 'The editor dashboard link must be a Fathom share link (https://app.usefathom.com/share/…).';
            }
        }

        if ($input['ga4_enabled'] === 'y') {
            if (!ctype_digit($input['ga4_property_id'])) {
                $errors[] = 'The GA4 property ID must be numeric — the G-… measurement ID will not work.';
            }
            if (!$hasGoogle) {
                $errors[] = 'Google Analytics needs a service account key.';
            }
        }

        if ($input['gsc_property'] !== '' && !$hasGoogle) {
            $errors[] = 'Search Console needs a service account key.';
        }

        return $errors;
    }
}
