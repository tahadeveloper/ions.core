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

/*
|--------------------------------------------------------------------------
| Expandable panels (Phase 14.3)
|--------------------------------------------------------------------------
*/

test('the injected bar carries the collapsed strip and the expandable panels', function () {
    $content = (string) Kernel::handle(Request::create('/toolbar-page'))->getContent();

    expect($content)
        // collapsed strip + brand + close button
        ->toContain('class="idt-strip"')
        ->toContain('idt-brand')
        ->toContain('class="idt-close"')
        // each panel marker
        ->toContain('data-idt-panel="queries"')
        ->toContain('data-idt-panel="request"')
        ->toContain('data-idt-panel="timing"')
        // segments toggle their panel
        ->toContain('data-idt-toggle="queries"')
        ->toContain('data-idt-toggle="request"')
        // inlined assets present once
        ->toContain('<style>')
        ->toContain('<script>');
    expect(substr_count($content, '<style>') >= 1)->toBeTrue();
});

test('the request panel reports method, path and status', function () {
    $content = (string) Kernel::handle(Request::create('/toolbar-page'))->getContent();

    // Inside the request panel (key/value rows).
    expect($content)
        ->toContain('<td>method</td>')
        ->toContain('<td>status</td>')
        ->toContain('<td>content-type</td>')
        ->toContain('/toolbar-page');
});

test('the queries panel hints to enable the log when capturing is off', function () {
    $content = (string) Kernel::handle(Request::create('/toolbar-page'))->getContent();

    expect($content)->toContain('set database.query_log to capture SQL');
});

test('the queries panel lists captured SQL with HTML-escaped markup', function () {
    config(['database.query_log' => true]);
    DB::connection()->enableQueryLog();

    $content = (string) Kernel::handle(Request::create('/toolbar-xss-query'))->getContent();

    expect($content)
        // the markup in the SQL is escaped, never live
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>alert(1)</script>')
        // per-query binding count rendered
        ->toContain('binding');
});

/*
|--------------------------------------------------------------------------
| Injection safety unchanged (Phase 14.3)
|--------------------------------------------------------------------------
*/

test('the stale Content-Length header is stripped after injecting', function () {
    $response = Kernel::handle(Request::create('/toolbar-with-length'));

    expect((string) $response->getContent())->toContain(TOOLBAR_MARKER)
        ->and($response->headers->has('Content-Length'))->toBeFalse();
});

test('a response with no </body> is passed through untouched', function () {
    $response = Kernel::handle(Request::create('/toolbar-no-body'));
    $content = (string) $response->getContent();

    expect($content)->not->toContain(TOOLBAR_MARKER)
        ->and($content)->toBe('<html><p>no body tag here</p></html>');
});

test('a render failure degrades to the original response unmodified', function () {
    $original = '<html><body>untouched</body></html>';

    // A Response whose setContent throws forces a failure AFTER the </body>
    // probe — the middleware must swallow it and return the response as-is.
    $response = new class ($original) extends \Symfony\Component\HttpFoundation\Response {
        private bool $constructed = false;

        public function setContent(?string $content): static
        {
            if ($this->constructed) {
                throw new \RuntimeException('boom');
            }
            $this->constructed = true;

            return parent::setContent($content);
        }
    };

    $middleware = new \Ions\Http\Middleware\DebugToolbarMiddleware();
    $result = $middleware->handle(Request::create('/toolbar-page'), fn () => $response);

    expect($result)->toBe($response)
        ->and((string) $result->getContent())->toBe($original)
        ->and((string) $result->getContent())->not->toContain(TOOLBAR_MARKER);
});

test('the injected styles are scoped and do not restyle the host page', function () {
    $content = (string) Kernel::handle(Request::create('/toolbar-page'))->getContent();

    $style = substr($content, strpos($content, '<style>') + 7);
    $style = substr($style, 0, strpos($style, '</style>'));

    // Every rule is nested under the toolbar id; no bare element selectors.
    expect($style)->toContain('#ions-debug-toolbar')
        ->not->toMatch('/(^|\})\s*body\s*\{/')
        ->and($style)->not->toMatch('/(^|\})\s*a\s*\{/');
});
