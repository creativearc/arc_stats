<?php

return [
    'author'         => 'Creative Arc',
    'author_url'     => 'https://creativearc.com',
    'name'           => 'Arc Stats',
    'description'    => 'Site analytics on the Control Panel dashboard: Fathom and/or Google Analytics 4, plus Google Search Console.',
    'version'        => '1.0.0',
    'namespace'      => 'CreativeArc\ArcStats',
    'settings_exist' => true,
    'commands'       => [
        'arc_stats:index' => \CreativeArc\ArcStats\Commands\IndexCommand::class,
    ],
];
