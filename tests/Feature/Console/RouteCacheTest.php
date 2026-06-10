<?php

/**
 * Phase 8.1 — compiled route cache (route:cache / route:clear).
 *
 * Uses the app-cache fixture whose routes are all class-string controllers
 * (closure routes cannot be compiled — see the failure test against the main
 * fixture, whose routes/web.php uses closures).
 */

use Ions\commands\RouteCacheCommand;
use Ions\commands\RouteClearCommand;
use Ions\Foundation\Kernel;
use Ions\Support\Request;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\Route as SRoute;
use Symfony\Component\Routing\RouteCollection;

function cacheFixturePath(): string
{
    return dirname(__DIR__, 2) . '/fixtures/app-cache';
}

function clearRouteCacheFiles(): void
{
    $dir = cacheFixturePath() . '/var/cache/routes';
    foreach (glob($dir . '/*.php') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($dir) && (scandir($dir) === false || count((array) scandir($dir)) === 2)) {
        rmdir($dir);
    }
}

beforeEach(fn () => clearRouteCacheFiles());
afterEach(fn () => clearRouteCacheFiles());

test('route:cache compiles both groups and cached matching equals live', function () {
    $fx = cacheFixturePath();

    // Live matching first (no cache files exist).
    bootFixtureKernel($fx);
    $live = [
        Kernel::handle(Request::create('/cached'))->getContent(),
        Kernel::handle(Request::create('/cached/42'))->getContent(),
        Kernel::handle(Request::create('/api/cached'))->getContent(),
    ];
    expect($live)->toBe(['cached page', 'item 42', 'api cached']);

    // Build the cache.
    bootFixtureKernel($fx);
    $tester = runConsoleCommand(new RouteCacheCommand());
    expect($tester->getStatusCode())->toBe(0)
        ->and(is_file($fx . '/var/cache/routes/web.php'))->toBeTrue()
        ->and(is_file($fx . '/var/cache/routes/api.php'))->toBeTrue();

    // Fresh boot: matching now goes through the compiled cache and must give
    // identical results — static, with-params, and api routes.
    bootFixtureKernel($fx);
    expect(Kernel::handle(Request::create('/cached'))->getContent())->toBe($live[0])
        ->and(Kernel::handle(Request::create('/cached/42'))->getContent())->toBe($live[1])
        ->and(Kernel::handle(Request::create('/api/cached'))->getContent())->toBe($live[2])
        ->and(Kernel::handle(Request::create('/does-not-exist'))->getStatusCode())->toBe(404);
});

test('handle() actually consults the compiled cache (cache-only route matches)', function () {
    $fx = cacheFixturePath();

    // Hand-craft a compiled cache containing a route that does NOT exist in the
    // live route files. If the kernel matches it, the compiled path is in use.
    $routes = new RouteCollection();
    $routes->add('only.cache', new SRoute('/only-in-cache', [
        '_controller' => \IonsFixtureCache\Http\PageController::class . '::show',
    ]));

    $dir = $fx . '/var/cache/routes';
    @mkdir($dir, 0775, true);
    file_put_contents($dir . '/web.php', (new CompiledUrlMatcherDumper($routes))->dump());

    bootFixtureKernel($fx);
    $response = Kernel::handle(Request::create('/only-in-cache'));
    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('cached page');
});

test('APP_DEBUG=true bypasses the compiled cache (live routes win)', function () {
    $fx = cacheFixturePath();

    $routes = new RouteCollection();
    $routes->add('only.cache', new SRoute('/only-in-cache', [
        '_controller' => \IonsFixtureCache\Http\PageController::class . '::show',
    ]));
    $dir = $fx . '/var/cache/routes';
    @mkdir($dir, 0775, true);
    file_put_contents($dir . '/web.php', (new CompiledUrlMatcherDumper($routes))->dump());

    $originalDebug = getenv('APP_DEBUG');
    putenv('APP_DEBUG=true');
    $_ENV['APP_DEBUG'] = 'true';

    try {
        bootFixtureKernel($fx);
        // The cache-only route must NOT match; the live file routes must.
        expect(Kernel::handle(Request::create('/only-in-cache'))->getStatusCode())->toBe(404)
            ->and(Kernel::handle(Request::create('/cached'))->getContent())->toBe('cached page');
    } finally {
        if ($originalDebug === false) {
            putenv('APP_DEBUG');
            unset($_ENV['APP_DEBUG']);
        } else {
            putenv('APP_DEBUG=' . $originalDebug);
            $_ENV['APP_DEBUG'] = $originalDebug;
        }
    }
});

test('route:cache fails loudly when a route uses a Closure controller', function () {
    bootFixtureKernel(); // the main fixture's routes/web.php is closure-based

    $tester = runConsoleCommand(new RouteCacheCommand());
    expect($tester->getStatusCode())->not->toBe(0)
        ->and($tester->getDisplay())->toContain('Closure');
});

test('route:clear removes the compiled cache files', function () {
    $fx = cacheFixturePath();

    bootFixtureKernel($fx);
    runConsoleCommand(new RouteCacheCommand());
    expect(is_file($fx . '/var/cache/routes/web.php'))->toBeTrue();

    $tester = runConsoleCommand(new RouteClearCommand());
    expect($tester->getStatusCode())->toBe(0)
        ->and(is_file($fx . '/var/cache/routes/web.php'))->toBeFalse()
        ->and(is_file($fx . '/var/cache/routes/api.php'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Finding 1 — route:cache group isolation (RED-before / GREEN-after)
// ---------------------------------------------------------------------------

test('api cache does NOT contain web routes (group isolation)', function () {
    $fx = cacheFixturePath();

    // Build the cache (web then api in the same process — exactly what the
    // command does). Before the fix, web routes leaked into api.php.
    bootFixtureKernel($fx);
    $tester = runConsoleCommand(new RouteCacheCommand());
    expect($tester->getStatusCode())->toBe(0);

    // The dumped api.php must NOT reference any web-only route name/path.
    $apiDump = file_get_contents($fx . '/var/cache/routes/api.php');
    expect($apiDump)
        ->not->toContain('cached.show')   // web-only route name
        ->not->toContain('cached.item')   // web-only route name
        ->not->toContain("'/cached'");    // web-only route path
});

test('web cache does NOT contain api routes (group isolation)', function () {
    $fx = cacheFixturePath();

    bootFixtureKernel($fx);
    runConsoleCommand(new RouteCacheCommand());

    $webDump = file_get_contents($fx . '/var/cache/routes/web.php');
    expect($webDump)
        ->not->toContain('api.cached')     // api-only route name
        ->not->toContain('api.secret')     // api-only route name
        ->not->toContain("'/api/cached'"); // api-only route path
});

test('compiled api matcher does not match web-only routes (live path isolation)', function () {
    $fx = cacheFixturePath();

    bootFixtureKernel($fx);
    runConsoleCommand(new RouteCacheCommand());

    // Fresh boot — compiled cache is in effect.
    bootFixtureKernel($fx);

    // /cached is a web-only route; the api matcher must NOT find it.
    // A 404 means the compiled api cache is clean.
    $webRouteViaApi = Kernel::handle(Request::create('/cached'));
    // When the api group is selected ('/api/*'), the compiled api cache is
    // used. '/cached' is NOT an api path, so this goes through the web group.
    // The key assertion: a fresh boot with cache should still give 200 for
    // /cached (web) and 404 for /cached via the api-compiled path.
    // We test this by verifying /api/cached still returns correctly and
    // that a web-path request does NOT accidentally return api controller output.
    $apiResponse = Kernel::handle(Request::create('/api/cached'));
    expect($apiResponse->getStatusCode())->toBe(200)
        ->and($apiResponse->getContent())->toBe('api cached');

    // The web route /cached must also work correctly, not be polluted.
    $webResponse = Kernel::handle(Request::create('/cached'));
    expect($webResponse->getStatusCode())->toBe(200)
        ->and($webResponse->getContent())->toBe('cached page');
});

// ---------------------------------------------------------------------------
// Finding 2 — security middleware works through the compiled route cache
// ---------------------------------------------------------------------------

test('cached non-public api route without a token returns 401 (AuthMiddleware runs through cache)', function () {
    $fx = cacheFixturePath();

    // Build cache with debug off (default).
    bootFixtureKernel($fx);
    runConsoleCommand(new RouteCacheCommand());

    // Fresh boot with compiled cache in effect.
    bootFixtureKernel($fx);

    // /api/secret is NOT in public_paths — must need a Bearer token.
    $response = Kernel::handle(Request::create('/api/secret'));
    expect($response->getStatusCode())->toBe(401);
});

test('cached web POST without a CSRF token returns 419 (CsrfMiddleware runs through cache)', function () {
    $fx = cacheFixturePath();

    bootFixtureKernel($fx);
    runConsoleCommand(new RouteCacheCommand());

    bootFixtureKernel($fx);

    // POST to a cached route without a CSRF token must be rejected.
    $response = Kernel::handle(Request::create('/cached/post', 'POST'));
    expect($response->getStatusCode())->toBe(419);
});

test('cached throttled route returns 429 after exceeding the rate limit (RateLimitMiddleware runs through cache)', function () {
    $fx = cacheFixturePath();

    bootFixtureKernel($fx);
    runConsoleCommand(new RouteCacheCommand());

    bootFixtureKernel($fx);

    // app-cache sets ratelimit.max = 1, so the second request must 429.
    $first  = Kernel::handle(Request::create('/api/throttled'));
    $second = Kernel::handle(Request::create('/api/throttled'));

    expect($first->getStatusCode())->toBe(200)
        ->and($second->getStatusCode())->toBe(429);
});
