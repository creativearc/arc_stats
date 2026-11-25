<?php

$lang = [
    'arc_stats_module_name'        => 'Arc Stats',
    'arc_stats_module_description' => 'Site analytics on the Control Panel dashboard.',

    'as_settings'                  => 'Arc Stats Settings',
    'as_saved'                     => 'Settings saved',

    'as_fathom_enabled'            => 'Use Fathom Analytics',
    'as_fathom_enabled_desc'       => 'Adds Fathom traffic, pages and sources widgets. Can be on alongside Google Analytics; each gets its own widgets.',
    'as_ga4_enabled'               => 'Use Google Analytics 4',
    'as_ga4_enabled_desc'          => 'Adds Google Analytics traffic, pages and sources widgets. Uses the service account key below.',

    'as_fathom_api_key'            => 'Fathom API key',
    'as_fathom_api_key_desc'       => 'Create a read-only key at app.usefathom.com/api. Leave blank to keep the saved key.',
    'as_fathom_site_id'            => 'Fathom site ID',
    'as_fathom_site_id_desc'       => 'The short ID shown in your Fathom site settings, e.g. <code>ABCDEFGH</code>.',

    'as_fathom_share_url'          => 'Editor Fathom dashboard link',
    'as_fathom_share_url_desc'     => 'The "Fathom ↗" button on the Fathom widgets opens this link, for everyone. Use a shared-dashboard link (Fathom → Site settings → Sharing): the Fathom app itself only works for someone logged in to the account that owns the site. Left blank, there is no button.',

    'as_ga4_property_id'           => 'GA4 property ID',
    'as_ga4_property_id_desc'      => 'The numeric property ID (Admin → Property details), e.g. <code>123456789</code>. Not the <code>G-…</code> measurement ID.',

    'as_google_credentials'        => 'Google service account key',
    'as_google_credentials_desc'   => 'Used for both Google Analytics and Search Console. Paste the JSON key file. Leave blank to keep the saved key.',
    'as_gsc_property'              => 'Search Console property',
    'as_gsc_property_desc'         => '<code>example.com</code> for a domain property, or <code>https://example.com/</code> for a URL-prefix property. Leave blank to hide search terms.',

    'as_roles'                     => 'Who can see the %s widgets',
    'as_roles_desc'                => 'Leave all unticked to show them to everyone who can see Arc Stats widgets. Super Admins always see them. Members also need Arc Stats under their role\'s Add-on access, or Pro hides every Arc Stats widget from them.',
    'as_role_hidden'               => 'Hidden: your role is not allowed to see this source.',

    'as_google_setup'              => 'Google setup',

    // Widgets
    'as_widget_overview'           => 'Site Traffic',
    'as_widget_pages'              => 'Top Pages',
    'as_widget_sources'            => 'Top Sources',
    'as_widget_search_totals'      => 'Search Totals',
    'as_widget_search_queries'     => 'Search Queries',
    'as_widget_search_index'       => 'Index Status',
    'as_rising'                    => 'Rising',
    'as_falling'                   => 'Falling',
    'as_last_30'                   => 'Last 30 days',
    'as_not_configured'            => 'Arc Stats is not set up yet.',
    'as_configure'                 => 'Configure',
    'as_no_data'                   => 'No data for this period.',
    'as_vs_previous'               => 'vs. previous 30 days',
];
