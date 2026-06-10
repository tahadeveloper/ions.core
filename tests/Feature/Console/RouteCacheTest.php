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
        @unlink($file);
    }
    if (is_dir($dir)) {
        @rmdir($dir);
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
