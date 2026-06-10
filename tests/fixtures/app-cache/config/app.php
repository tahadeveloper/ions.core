<?php

return [
    'name' => 'IonsFixtureCache',
    'app_url' => 'http://localhost',
    'database_engine' => [],
    'templates' => [],
    'localization' => ['locale' => 'en'],

    // /api/cached authenticates nothing — keep it public so cached vs live
    // comparisons exercise the api group end-to-end.
    'auth' => [
        'public_paths' => [
            '/api/cached',
        ],
    ],
];
