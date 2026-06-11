<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\Support\DB;
use Ions\Support\Request;

/*
|--------------------------------------------------------------------------
| Debug toolbar lite (Phase 10.6)
|--------------------------------------------------------------------------
| DebugToolbarMiddleware is attached to the WEB stack only when APP_DEBUG is
| truthy at stack-build time (defaultMiddleware()), and injects a small
| footer bar before </body> in HTML responses. JSON/api responses and
| non-debug runs are byte-untouched. Escape hatch: app.debug_toolbar=false.
|
| APP_DEBUG is flipped per-test via the environment (the fixture .env has no
| APP_DEBUG), mirroring DebugPageTest.
*/

const TOOLBAR_MARKER = 'id="ions-debug-toolbar"';

beforeEach(function () {
    bootFixtureKernel();
    putenv('APP_DEBUG=true');
    $_ENV['APP_DEBUG'] = 'true';
});

afterEach(function () {
    putenv('APP_DEBUG');
    unset($_ENV['APP_DEBUG']);
});

test('the toolbar is injected into a debug HTML response before </body>', function () {
    $response = Kernel::handle(Request::create('/toolbar-page'));
    $content = (string) $response->getContent();

    expect($response->getStatusCode())->toBe(200)
        ->and($content)->toContain(TOOLBAR_MARKER)
        ->and($content)->toContain('toolbar fixture')
        // Injected INSIDE the document, not appended after it.
        ->and(strrpos($content, TOOLBAR_MARKER))->toBeLessThan(strrpos($content, '</body>'))
        // PHP + Ions version line.
        ->and($content)->toContain('PHP ' . PHP_VERSION);
});

test('the toolbar shows the request path and peak memory', function () {
    $content = (string) Kernel::handle(Request::create('/toolbar-page'))->getContent();

    expect($content)->toContain('/toolbar-page')
        ->and($content)->toMatch('/\d+(\.\d+)? MB/');
});

test('the toolbar reports "log off" when the query log is disabled', function () {
    $content = (string) Kernel::handle(Request::create('/toolbar-page'))->getContent();

    expect($content)->toContain('log off');
});

test('the toolbar shows the query count and total ms when the query log is on', function () {
    config(['database.query_log' => true]);
    DB::connection()->enableQueryLog();

    $content = (string) Kernel::handle(Request::create('/toolbar-query'))->getContent();

    expect($content)->toContain('queries: 1');
});

test('the toolbar is absent when APP_DEBUG is off', function () {
    putenv('APP_DEBUG');
    unset($_ENV['APP_DEBUG']);

    $content = (string) Kernel::handle(Request::create('/toolbar-page'))->getContent();

    expect($content)->not->toContain(TOOLBAR_MARKER);
});

test('the toolbar never touches api JSON responses', function () {
    $response = Kernel::handle(Request::create('/api/echo', 'POST', ['hello' => 'world']));
    $content = (string) $response->getContent();

    expect($content)->not->toContain(TOOLBAR_MARKER)
        ->and(json_decode($content, true))->toBeArray();
});

test('app.debug_toolbar => false disables the toolbar even in debug', function () {
    config(['app.debug_toolbar' => false]);

    $content = (string) Kernel::handle(Request::create('/toolbar-page'))->getContent();

    expect($content)->not->toContain(TOOLBAR_MARKER);
});
