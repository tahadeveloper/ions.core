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

/**
 * Drop the source-excerpt <pre> from rendered HTML. Direct-render tests throw
 * from THIS file, so the excerpt would echo the test's own literals (secrets,
 * expected markers) and corrupt contain/not-contain assertions.
 */
function stripSourceExcerpt(string $html): string
{
    return (string) preg_replace('/<pre class="excerpt">.*?<\/pre>/s', '', $html);
}

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

test('nested sensitive body params are redacted on the debug page', function () {
    // Reviewer probe (CRITICAL-1): top-level keys were redacted but array
    // values were json_encoded raw, leaking user[password].
    $request = Request::create('/boom', 'POST', [
        'user' => ['password' => 'nested-secret-value', 'name' => 'visible-name'],
    ]);

    $html = stripSourceExcerpt((new DebugPage())->render(new \RuntimeException('x'), $request, 500));

    expect($html)->not->toContain('nested-secret-value')
        ->and($html)->toContain('[REDACTED]')
        ->and($html)->toContain('visible-name');
});

test('decoded basic-auth headers (php-auth-*) are redacted on the debug page', function () {
    // Reviewer probe (CRITICAL-2): Symfony ServerBag decodes Basic credentials
    // into php-auth-user / php-auth-pw headers, which were printed raw.
    $request = Request::create('/boom', 'GET', [], [], [], [
        'PHP_AUTH_USER' => 'basic-user-name',
        'PHP_AUTH_PW' => 'basic-secret-pw',
    ]);

    $html = stripSourceExcerpt((new DebugPage())->render(new \RuntimeException('x'), $request, 500));

    expect($html)->not->toContain('basic-secret-pw')
        ->and($html)->not->toContain('basic-user-name')
        ->and($html)->toContain('[REDACTED]');
});

test('the getPrevious() chain is rendered', function () {
    $content = Kernel::handle(Request::create('/boom-chained'))->getContent();

    expect($content)->toContain('LogicException')
        ->and($content)->toContain('root cause detail');
});

test('the previous chain is capped at MAX_CHAIN entries', function () {
    // Build a 7-deep getPrevious() chain: only the first 5 may render.
    $e = null;
    for ($i = 7; $i >= 1; $i--) {
        $e = new \LogicException('chain-msg-' . $i, 0, $e);
    }
    $outer = new \RuntimeException('outer-top', 0, $e);

    $html = stripSourceExcerpt((new DebugPage())->render($outer, Request::create('/x'), 500));

    expect($html)->toContain('chain-msg-1')
        ->and($html)->toContain('chain-msg-5')
        ->and($html)->not->toContain('chain-msg-6')
        ->and($html)->not->toContain('chain-msg-7');
});

test('the stack trace is capped at MAX_FRAMES with a truncation marker', function () {
    $recurse = function (int $n) use (&$recurse): void {
        if ($n <= 0) {
            throw new \RuntimeException('deep-recursion');
        }
        $recurse($n - 1);
    };

    try {
        $recurse(80); // > MAX_FRAMES (50)
        $this->fail('expected throw');
    } catch (\RuntimeException $caught) {
    }

    $html = stripSourceExcerpt((new DebugPage())->render($caught, Request::create('/x'), 500));

    expect($html)->toContain('#49')          // last rendered frame index
        ->and($html)->not->toContain('#50')  // capped
        ->and($html)->toContain('more frames');
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
