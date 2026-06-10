<?php

return [
    'name' => 'IonsFixtureCache',
    'app_url' => 'http://localhost',
    'database_engine' => [],
    'templates' => [],
    'localization' => ['locale' => 'en'],

    // Public API paths — bypass AuthMiddleware (they authenticate rather than
    // require a prior token, or are test fixtures for security probes).
    'auth' => [
        'public_paths' => [
            '/api/cached',
            // /api/throttled is public so the 429-through-cache test can reach
            // RateLimitMiddleware without needing a JWT token.
            '/api/throttled',
        ],
    ],

    // CSRF enabled (default) — used by the security-through-cache 419 test.
    'csrf' => [
        'enabled' => true,
    ],

    // Throttle alias so routes/api.php can attach rate-limiting.
    'middleware_aliases' => [
        'throttle' => \Ions\Http\Middleware\RateLimitMiddleware::class,
    ],

    // Tiny window so the 429 test can trip it quickly.
    'ratelimit' => [
        'max'   => 1,
        'decay' => 60,
    ],
];
