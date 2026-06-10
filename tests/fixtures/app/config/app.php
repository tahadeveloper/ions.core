<?php

return [
    'name' => 'IonsFixture',
    'app_url' => 'http://localhost',
    'database_engine' => ['db'],
    'templates' => [],
    'localization' => ['locale' => 'en'],

    // Per-route middleware aliases. 'throttle' rate-limits the login route.
    'middleware_aliases' => [
        'throttle' => \Ions\Http\Middleware\RateLimitMiddleware::class,
    ],

    // Low limit so the rate-limit test can exceed the window quickly.
    'ratelimit' => [
        'max'   => 3,
        'decay' => 60,
    ],

    // API endpoints that authenticate (rather than require a prior token) and
    // therefore bypass AuthMiddleware.
    'auth' => [
        'public_paths' => [
            '/api/auth/login',
            '/api/auth/refresh',
            '/api/auth/logout',
            '/api/auth/password/forgot',
            '/api/auth/password/reset',
        ],
    ],
];
