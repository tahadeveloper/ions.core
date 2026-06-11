<?php

// Drift guard: skeleton/config/ mirrors this config set with production values —
// add new framework config keys in BOTH places.
return [
    'name' => 'IonsFixture',
    'app_url' => 'http://localhost',
    'database_engine' => ['db'],
    'templates' => [],
    'localization' => ['locale' => 'en'],

    // Twig defaults so ViewFactory::make() (no overrides) resolves without a
    // real source dir (MailableTest writes its templates into the temp dir).
    // 'paths' registers the @admin namespace, host-root relative (9.2).
    'twig' => [
        'source' => sys_get_temp_dir(),
        'cache' => false,
        'paths' => ['admin' => 'views/admin'],
    ],

    // CORS is deny-by-default since 4.1 (D8-1): no origins => no CORS headers.
    // Kept EXPLICIT here so the fixture never depends on the middleware default.
    'cors' => [
        'origins' => [],
    ],

    // Per-route middleware aliases. 'throttle' rate-limits the login route;
    // 'signed' validates URL signatures (signedRoute()).
    'middleware_aliases' => [
        'throttle' => \Ions\Http\Middleware\RateLimitMiddleware::class,
        'signed' => \Ions\Http\Middleware\ValidateSignatureMiddleware::class,
    ],

    // Built-in /up health endpoint (10.6). EXPLICIT mirror of the skeleton
    // defaults (drift guard): enabled, no checks token configured — the
    // HealthEndpointTest sets a token per-test when gating the doctor JSON.
    'health' => [
        'enabled' => true,
        'token' => null,
    ],

    // Debug toolbar (10.6) in-debug escape hatch — default shown EXPLICITLY
    // per the drift guard; DebugToolbarTest flips it to false.
    'debug_toolbar' => true,

    // Low limit so the rate-limit test can exceed the window quickly.
    'ratelimit' => [
        'max'   => 3,
        'decay' => 60,
    ],

    // Input keys never flashed by RedirectResponse::withInput() (10.3).
    // EXPLICIT here (mirrors the in-code default) per the drift guard above.
    'forms' => [
        'dont_flash' => ['password', 'password_confirmation', 'current_password'],
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
            // Unauthenticated echo endpoint used by the Ions\Testing kit tests.
            '/api/echo',
        ],
    ],
];
