<?php

declare(strict_types=1);

use Ions\Bundles\Logs;
use Ions\Bundles\Path;
use Ions\Foundation\Kernel;
use Ions\Support\Request;

/*
|--------------------------------------------------------------------------
| Custom HTML error pages (Phase 10.6)
|--------------------------------------------------------------------------
| In production (APP_DEBUG off) the ExceptionHandler's HTML path tries host
| templates through the shared Twig env before the built-in minimal page:
|
|   errors/{status}.twig  ->  errors/{4xx|5xx}.twig  ->  built-in <h1>
|
| Context: status (int), message (the SAME client-safe message the minimal
| page shows), request_path. Rendering must NEVER throw: a broken template
| logs a warning to view.log and falls back to the built-in page. Debug mode
| keeps the DebugPage; API/JSON rendering is byte-identical.
|
| Fixture templates live in tests/fixtures/app/views/errors/ — the fixture's
| default twig source is sys_get_temp_dir() (no errors/ dir there), so other
| suites keep the pre-10.6 built-in pages; these tests point app.twig.source
| at the committed views/ dir like ViewRenderingTest does.
*/

beforeEach(function () {
    bootFixtureKernel();
    config(['app.twig.source' => Path::root('views')]);
});

afterEach(function () {
    putenv('APP_DEBUG');
    unset($_ENV['APP_DEBUG']);
});

test('a web 404 renders the host errors/404.twig with status, message and request_path', function () {
    $response = Kernel::handle(Request::create('/no-such-page'));
    $content = (string) $response->getContent();

    expect($response->getStatusCode())->toBe(404)
        ->and($content)->toContain('CUSTOM-404')
        ->and($content)->toContain('404')
        ->and($content)->toContain('Page route not found')
        ->and($content)->toContain('/no-such-page')
        ->and((string) $response->headers->get('Content-Type'))->toContain('text/html');
});

test('a 403 with no errors/403.twig falls back to the status-class errors/4xx.twig', function () {
    $response = Kernel::handle(Request::create('/forbidden'));
    $content = (string) $response->getContent();

    expect($response->getStatusCode())->toBe(403)
        ->and($content)->toContain('CUSTOM-4XX')
        ->and($content)->toContain('403');
});

test('a broken errors/500.twig falls back to the built-in page and logs a view.log warning', function () {
    Logs::reset('view.log');

    $response = Kernel::handle(Request::create('/boom'));

    // Byte-identical built-in fallback (the 8.4 shape).
    expect($response->getStatusCode())->toBe(500)
        ->and((string) $response->getContent())->toBe('<h1>500 Internal Server Error</h1>');

    $log = (string) file_get_contents(Path::logs('view.log'));
    expect($log)->toContain('error page');
});

test('debug mode still renders the DebugPage, not the custom template', function () {
    putenv('APP_DEBUG=true');
    $_ENV['APP_DEBUG'] = 'true';

    $response = Kernel::handle(Request::create('/no-such-page'));
    $content = (string) $response->getContent();

    expect($response->getStatusCode())->toBe(404)
        ->and($content)->toContain('exc-class')
        ->and($content)->not->toContain('CUSTOM-404');
});

test('api 404 JSON is byte-identical (custom templates never touch the JSON path)', function () {
    $response = Kernel::handle(Request::create('/api/definitely-missing'));

    expect($response->getStatusCode())->toBe(404)
        ->and(json_decode((string) $response->getContent(), true))->toBe([
            'status' => 'error',
            'message' => 'Page route not found',
            'code' => 404,
        ]);
});
