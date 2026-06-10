<?php

declare(strict_types=1);

/**
 * Feature tests for the debug-only N+1 detector wiring:
 *
 *   DatabaseProvider::boot() attaches Ions\Database\Listeners\DetectNPlusOne
 *   to the RequestHandled event ONLY when APP_DEBUG is truthy AND
 *   config('database.query_log') is enabled AND
 *   config('database.nplusone.enabled') is not false. The listener analyzes
 *   the bounded query log at request end and logs one warning per offending
 *   pattern to var/logs/performance.log. It must never throw.
 */

use Ions\Database\Listeners\DetectNPlusOne;
use Ions\Events\RequestHandled;
use Ions\Foundation\Kernel;
use Ions\Providers\DatabaseProvider;
use Ions\Support\DB;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->originalDebug = getenv('APP_DEBUG');
    bootFixtureKernel();
    \Ions\Bundles\Logs::reset('performance.log');
});

afterEach(function () {
    if ($this->originalDebug === false) {
        putenv('APP_DEBUG');
        unset($_ENV['APP_DEBUG']);
    } else {
        putenv('APP_DEBUG=' . $this->originalDebug);
        $_ENV['APP_DEBUG'] = $this->originalDebug;
    }
});

/** Turn debug on for the current process (restored in afterEach). */
function nPlusOneEnableDebug(): void
{
    putenv('APP_DEBUG=1');
    $_ENV['APP_DEBUG'] = '1';
}

/** Re-run DatabaseProvider::boot() so it re-reads query_log/debug and attaches the listener. */
function nPlusOneRebootProvider(): void
{
    (new DatabaseProvider(Kernel::app()))->boot();
}

/** Create + seed a small table and run $n identical single-row lookups through the logged connection. */
function nPlusOneCraftBurst(int $n): void
{
    $schema = Kernel::app()->get('db')->getConnection()->getSchemaBuilder();
    $schema->dropIfExists('nplus_items');
    $schema->create('nplus_items', function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id');
        $t->string('name');
    });

    for ($i = 1; $i <= $n; $i++) {
        DB::connection()->insert('insert into nplus_items (name) values (?)', ['item-' . $i]);
    }

    // The crafted N+1: one single-row SELECT per id — same pattern, different bindings.
    for ($i = 1; $i <= $n; $i++) {
        DB::connection()->select('select * from nplus_items where id = ?', [$i]);
    }
}

function nPlusOnePerformanceLog(): string
{
    $path = \Ions\Bundles\Path::logs('performance.log');

    return file_exists($path) ? (string) file_get_contents($path) : '';
}

test('a crafted N+1 burst logs a performance warning with pattern, count and path', function () {
    nPlusOneEnableDebug();
    config(['database.query_log' => true]);
    nPlusOneRebootProvider();

    nPlusOneCraftBurst(6);

    event(new RequestHandled(Request::create('/widgets'), new Response('ok')));

    $log = nPlusOnePerformanceLog();
    expect($log)->toContain('Possible N+1')
        ->and($log)->toContain('select * from nplus_items where id = ?')
        ->and($log)->toContain('6 times')
        ->and($log)->toContain('/widgets');
});

test('no warning when the burst stays below the configured threshold', function () {
    nPlusOneEnableDebug();
    config(['database.query_log' => true, 'database.nplusone.threshold' => 10]);
    nPlusOneRebootProvider();

    nPlusOneCraftBurst(6);

    event(new RequestHandled(Request::create('/widgets'), new Response('ok')));

    expect(nPlusOnePerformanceLog())->not->toContain('Possible N+1');
});

test('database.nplusone.enabled => false disables the detector entirely', function () {
    nPlusOneEnableDebug();
    config(['database.query_log' => true, 'database.nplusone.enabled' => false]);
    nPlusOneRebootProvider();

    nPlusOneCraftBurst(6);

    event(new RequestHandled(Request::create('/widgets'), new Response('ok')));

    expect(nPlusOnePerformanceLog())->not->toContain('Possible N+1');
});

test('APP_DEBUG off means no listener and an untouched performance log', function () {
    // Explicitly debug-off — production shape.
    putenv('APP_DEBUG=0');
    $_ENV['APP_DEBUG'] = '0';
    config(['database.query_log' => true]);
    nPlusOneRebootProvider();

    nPlusOneCraftBurst(6);

    event(new RequestHandled(Request::create('/widgets'), new Response('ok')));

    expect(file_exists(\Ions\Bundles\Path::logs('performance.log')))->toBeFalse();
});

test('query log disabled means no listener even in debug', function () {
    nPlusOneEnableDebug();
    // query_log stays false (fixture default) — zero hot-path wiring.
    nPlusOneRebootProvider();

    nPlusOneCraftBurst(6); // connection is not logging; nothing accumulates

    event(new RequestHandled(Request::create('/widgets'), new Response('ok')));

    expect(file_exists(\Ions\Bundles\Path::logs('performance.log')))->toBeFalse();
});

test('the warning fires through a full Kernel::handle() request lifecycle', function () {
    nPlusOneEnableDebug();
    config(['database.query_log' => true]);
    nPlusOneRebootProvider();

    nPlusOneCraftBurst(5);

    // RequestHandled fires on every handle() path (including 404s).
    Kernel::handle(Request::create('/no-such-route'));

    $log = nPlusOnePerformanceLog();
    expect($log)->toContain('Possible N+1')
        ->and($log)->toContain('select * from nplus_items where id = ?');
});

test('provider boot is idempotent: re-booting twice logs each pattern once', function () {
    nPlusOneEnableDebug();
    config(['database.query_log' => true]);
    nPlusOneRebootProvider();
    nPlusOneRebootProvider(); // second boot must not attach a second listener

    nPlusOneCraftBurst(6);

    event(new RequestHandled(Request::create('/widgets'), new Response('ok')));

    expect(substr_count(nPlusOnePerformanceLog(), 'Possible N+1'))->toBe(1);
});

test('the listener never throws when the db binding is broken', function () {
    nPlusOneEnableDebug();
    config(['database.query_log' => true]);

    Kernel::app()->instance('db', new stdClass()); // getConnection() will blow up

    $listener = new DetectNPlusOne();
    $event = new RequestHandled(Request::create('/widgets'), new Response('ok'));

    expect(fn () => $listener->handle($event))->not->toThrow(Throwable::class)
        ->and(file_exists(\Ions\Bundles\Path::logs('performance.log')))->toBeFalse();

    // Repair the container for subsequent tests in this process.
    \Illuminate\Support\Facades\Facade::clearResolvedInstance('db');
    bootFixtureKernel();
});
