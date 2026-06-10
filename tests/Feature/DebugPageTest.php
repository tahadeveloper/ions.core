<?php

use Ions\Foundation\Kernel;
use Ions\Http\DebugPage;
use Ions\Support\Request;

/*
 * Feature coverage for the rich debug error page (Phase 8.4e).
 *
 * APP_DEBUG is flipped on per-test via the environment (the fixture .env has
 * no APP_DEBUG, so other suites keep running in "production" mode). The page
 * is rendered through the real Kernel::handle() pipeline against fixture
 * routes that throw.
 */

beforeEach(function () {
    bootFixtureKernel();
    putenv('APP_DEBUG=true');
    $_ENV['APP_DEBUG'] = 'true';
});

afterEach(function () {
    putenv('APP_DEBUG');
    unset($_ENV['APP_DEBUG']);
});

test('debug page shows exception class, message and status', function () {
    $response = Kernel::handle(Request::create('/boom'));

    expect($response->getStatusCode())->toBe(500)
        ->and($response->headers->get('Content-Type'))->toContain('text/html')
        ->and($response->getContent())->toContain('RuntimeException')
        ->and($response->getContent())->toContain('SENSITIVE')
        ->and($response->getContent())->toContain('500');
});

test('debug page shows a source excerpt with the throwing line highlighted', function () {
    $content = Kernel::handle(Request::create('/boom'))->getContent();

    // The throwing file (fixture routes/web.php) and a highlighted line.
    expect($content)->toContain('web.php')
        ->and($content)->toContain('line-err');
});

test('debug page lists stack-trace frames and the request method + URI', function () {
    $content = Kernel::handle(Request::create('/boom'))->getContent();

    expect($content)->toContain('Stack trace')
        ->and($content)->toContain('GET')
        ->and($content)->toContain('/boom');
});

test('Authorization and Cookie headers are redacted on the debug page', function () {
    $request = Request::create('/boom', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer super-secret-token-value',
        'HTTP_COOKIE' => 'session=secret-cookie-value',
    ]);

    $content = Kernel::handle($request)->getContent();

    expect($content)->not->toContain('super-secret-token-value')
        ->and($content)->not->toContain('secret-cookie-value')
        ->and($content)->toContain('[REDACTED]');
});

test('password query param is redacted on the debug page', function () {
    $content = Kernel::handle(Request::create('/boom?password=hunter2&name=ok'))->getContent();

    expect($content)->not->toContain('hunter2')
        ->and($content)->toContain('[REDACTED]')
        ->and($content)->toContain('ok');
});

test('the getPrevious() chain is rendered', function () {
    $content = Kernel::handle(Request::create('/boom-chained'))->getContent();

    expect($content)->toContain('LogicException')
        ->and($content)->toContain('root cause detail');
});

test('a missing source file does not break rendering', function () {
    $caught = null;
    try {
        eval("throw new \\RuntimeException('eval-thrown');");
    } catch (\Throwable $e) {
        $caught = $e;
    }

    // eval'd code reports a pseudo file ("... : eval()'d code") that does not
    // exist on disk — the renderer must degrade gracefully, not throw.
    $html = (new DebugPage())->render($caught, Request::create('/x'), 500);

    expect($html)->toContain('RuntimeException')
        ->and($html)->toContain('eval-thrown');
});

test('debug page never dumps env vars or config', function () {
    $content = Kernel::handle(Request::create('/boom'))->getContent();

    // APP_KEY is in the fixture .env — it must never appear on the page.
    expect($content)->not->toContain('0123456789abcdef0123456789abcdef');
});

test('JSON requests still get JSON (debug page is HTML-only)', function () {
    $request = Request::create('/boom', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $response = Kernel::handle($request);

    expect($response->headers->get('Content-Type'))->toContain('json');
    $payload = json_decode((string) $response->getContent(), true);
    expect($payload)->toMatchArray(['status' => 'error'])
        ->and((string) $response->getContent())->not->toContain('<html');
});
