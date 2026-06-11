<?php

declare(strict_types=1);

use Ions\Bundles\Path;

// Drift guard: tests/fixtures/app/config/ (in ions.core) mirrors this config set —
// add new framework config keys in BOTH places.
return [
    'name' => env('APP_NAME', 'Ions'),
    'app_url' => env('APP_URL', 'http://localhost:8000'),

    // Database engines to boot. 'db' wires Illuminate Eloquent from
    // config/database.php (connections are lazy — nothing connects until used).
    'database_engine' => ['db'],

    'localization' => ['locale' => 'en'],
    'templates' => ['twig'],

    // Twig: templates live in views/, compiled cache in var/templates/.
    'twig' => [
        'source' => Path::views(''),
        'cache' => Path::templates(''),
    ],

    // CORS is deny-by-default since 4.1: with no origins configured NO CORS
    // headers are emitted at all. Add origins explicitly when serving
    // cross-origin traffic (see UPGRADE-4.1.md):
    //     'cors' => ['origins' => ['https://app.example.com']],
    'cors' => [
        'origins' => [],
    ],

    // Behind a TLS-terminating reverse proxy / load balancer? List the
    // proxies whose X-Forwarded-* headers to trust (IPs/CIDRs, or '*' for
    // the directly connecting peer) — required there for isSecure(), client
    // IPs, HSTS and session cookie_secure => 'auto'. Serving PHP directly
    // needs none. See docs/config.md#apptrusted_proxies and docs/deploy.md.
    //     'trusted_proxies' => ['10.0.0.0/8'],
    //     'trusted_proxy_headers' => 'xff', // | 'aws-elb' | 'traefik' | 'forwarded'

    // Per-route middleware aliases, usable as ->middleware(['throttle']).
    // 'signed' rejects requests whose URL lacks a valid signedRoute()/
    // signedUrl() signature (403) — see docs/security.md.
    'middleware_aliases' => [
        'throttle' => \Ions\Http\Middleware\RateLimitMiddleware::class,
        'signed' => \Ions\Http\Middleware\ValidateSignatureMiddleware::class,
        // Requires a verified email on the authenticated user (12.4).
        'verified' => \Ions\Auth\Http\EnsureEmailVerified::class,
    ],

    // Rate-limit window for the 'throttle' alias (defaults shown).
    // 'ratelimit' => ['max' => 60, 'decay' => 60],

    // Input keys never flashed by redirect()->withInput() after a failed
    // web validation (defaults shown) — see docs/forms.md.
    // 'forms' => ['dont_flash' => ['password', 'password_confirmation', 'current_password']],

    'auth' => [
        // API paths that authenticate (rather than require a prior token)
        // and therefore bypass AuthMiddleware. Everything else under /api
        // requires a valid Bearer token.
        'public_paths' => [
            '/api/auth/login',
            '/api/auth/refresh',
            '/api/ping', // sample route — remove together with routes/api.php's ping
        ],

        // Email verification (12.4, Ions\Auth\EmailVerification):
        // - email_verification_redirect: where the 'verified' middleware
        //   (Ions\Auth\Http\EnsureEmailVerified) sends an unverified WEB user;
        //   API/JSON requests get a 403 instead.
        // - verify_throttle: per-email resend limiter for
        //   EmailVerification::sendVerification() (mirrors forgot_throttle).
        'email_verification_redirect' => '/email/verify',
        'verify_throttle' => ['max' => 3, 'decay' => 600],
    ],

    // Built-in GET /up health endpoint (10.6): 200 'ok' liveness for load
    // balancers; /up?checks=1&token=... additionally runs `ions doctor` and
    // answers its JSON — set a long random token to enable that. Disable the
    // route entirely with 'enabled' => false. See docs/deploy.md.
    'health' => [
        'enabled' => true,
        'token' => env('HEALTH_TOKEN'),
    ],

    // Debug toolbar (10.6): with APP_DEBUG on, HTML responses get a footer
    // bar (request ms, route, query count, memory, versions). Costs nothing
    // in production (never attached). Set false to hide it while debugging.
    // 'debug_toolbar' => true,

    // Security defaults (4.1) are intentionally NOT overridden here:
    //   - session cookies: secure/httponly/samesite=lax (config/session.php)
    //   - HSTS + Permissions-Policy headers (app.security.*)
    //   - upload magic-bytes validation (app.uploads.mime_map)
    // See UPGRADE-4.1.md before loosening any of them.
];
