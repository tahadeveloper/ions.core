<?php

/**
 * Phase 8.1 — routes are captured ONCE per process (per group) instead of
 * being re-required + re-scanned on every Kernel::handle() call.
 */

use Ions\Foundation\Kernel;
use Ions\Support\Request;

test('repeated handles reuse the captured route collection (no regrowth)', function () {
    bootFixtureKernel();

    Kernel::handle(Request::create('/ping'));
    $afterFirst = count(Kernel::RouteCollection()->all());

    Kernel::handle(Request::create('/ping'));
    Kernel::handle(Request::create('/forbidden'));

    // Before capture-once, every handle() re-required routes/web.php and added
    // a fresh random-named /cron/schedule route, growing the collection.
    expect(count(Kernel::RouteCollection()->all()))->toBe($afterFirst);
});

test('web and api groups both match correctly across repeated handles', function () {
    bootFixtureKernel();

    expect(Kernel::handle(Request::create('/ping'))->getStatusCode())->toBe(200)
        ->and(Kernel::handle(Request::create('/api/secret'))->getStatusCode())->toBe(401)
        ->and(Kernel::handle(Request::create('/ping'))->getStatusCode())->toBe(200)
        ->and(Kernel::handle(Request::create('/api/secret'))->getStatusCode())->toBe(401);
});

test('re-booting the kernel resets the per-group route cache', function () {
    bootFixtureKernel();
    expect(Kernel::handle(Request::create('/ping'))->getContent())->toBe('pong');

    // app2 has no route files at all — a stale cached collection from the first
    // fixture would still answer 'pong' here.
    bootFixtureKernel(__DIR__ . '/../fixtures/app2');
    expect(Kernel::handle(Request::create('/ping'))->getContent())->not->toBe('pong');

    bootFixtureKernel();
    expect(Kernel::handle(Request::create('/ping'))->getContent())->toBe('pong');
});
