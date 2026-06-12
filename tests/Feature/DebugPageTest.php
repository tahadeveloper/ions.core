<?php

use Ions\Foundation\Kernel;
use Ions\Http\DebugPage;
use Ions\Support\Request;

/*
 * Feature coverage for the interactive debug error page (Phase 14.2).
 *
 * APP_DEBUG is flipped on per-test via the environment (the fixture .env has
 * no APP_DEBUG, so other suites keep running in "production" mode). The page
 * is rendered through the real Kernel::handle() pipeline against fixture
 * routes that throw, or directly via (new DebugPage())->render().
 */

beforeEach(function () {
    bootFixtureKernel();
    putenv('APP_DEBUG=true');
    $_ENV['APP_DEBUG'] = 'true';
});

afterEach(function () {
    putenv('APP_DEBUG');
    unset($_ENV['APP_DEBUG']);
    config(['app.debug.editor' => null]);
});

/**
 * Drop the highlighted source panes from rendered HTML. Direct-render tests
 * throw from THIS file, so the excerpt would echo the test's own literals
 * (secrets, expected markers) and corrupt contain/not-contain assertions.
 */
function stripSourcePanes(string $html): string
{
    return (string) preg_replace('/<pre class="ion-code">.*?<\/pre>/s', '', $html);
}

test('debug page shows exception class, message and status', function () {
    $response = Kernel::handle(Request::create('/boom'));

    expect($response->getStatusCode())->toBe(500)
        ->and($response->headers->get('Content-Type'))->toContain('text/html')
        ->and($response->getContent())->toContain('RuntimeException')
        ->and($response->getContent())->toContain('SENSITIVE')
        ->and($response->getContent())->toContain('500');
});

test('debug page shows a highlighted source excerpt with the throwing line marked', function () {
    $content = Kernel::handle(Request::create('/boom'))->getContent();

    // SourceHighlighter output: tok-* spans + the error-line marker.
    expect($content)->toContain('web.php')
        ->and($content)->toContain('line-err')
        ->and($content)->toContain('tok-');
});

test('debug page lists stack-trace frames and the request method + URI', function () {
    $content = Kernel::handle(Request::create('/boom'))->getContent();

    expect($content)->toContain('GET')
        ->and($content)->toContain('/boom');
});

test('debug page renders the interactive tab labels', function () {
    $html = stripSourcePanes((new DebugPage())->render(new \RuntimeException('x'), Request::create('/x'), 500));

    expect($html)->toContain('Request')
        ->and($html)->toContain('Headers')
        ->and($html)->toContain('Cookies')
        ->and($html)->toContain('Session')
        ->and($html)->toContain('Context');
});

test('Authorization and Cookie headers are redacted on the debug page', function () {
    $request = Request::create('/x', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer super-secret-token-value',
        'HTTP_COOKIE' => 'session=secret-cookie-value',
    ]);

    // Direct render + strip source panes: the secrets live in this test file's
    // own source, which would otherwise echo back through a frame excerpt.
    $html = stripSourcePanes((new DebugPage())->render(new \RuntimeException('x'), $request, 500));

    expect($html)->not->toContain('super-secret-token-value')
        ->and($html)->not->toContain('secret-cookie-value')
        ->and($html)->toContain('[REDACTED]');
});

test('password query param is redacted on the debug page', function () {
    $request = Request::create('/x', 'GET', ['password' => 'hunter2', 'name' => 'ok']);

    $html = stripSourcePanes((new DebugPage())->render(new \RuntimeException('x'), $request, 500));

    expect($html)->not->toContain('hunter2')
        ->and($html)->toContain('[REDACTED]')
        ->and($html)->toContain('ok');
});

test('nested sensitive body params are redacted on the debug page', function () {
    $request = Request::create('/boom', 'POST', [
        'user' => ['password' => 'nested-secret-value', 'name' => 'visible-name'],
    ]);

    $html = stripSourcePanes((new DebugPage())->render(new \RuntimeException('x'), $request, 500));

    expect($html)->not->toContain('nested-secret-value')
        ->and($html)->toContain('[REDACTED]')
        ->and($html)->toContain('visible-name');
});

test('decoded basic-auth headers (php-auth-*) are redacted on the debug page', function () {
    $request = Request::create('/boom', 'GET', [], [], [], [
        'PHP_AUTH_USER' => 'basic-user-name',
        'PHP_AUTH_PW' => 'basic-secret-pw',
    ]);

    $html = stripSourcePanes((new DebugPage())->render(new \RuntimeException('x'), $request, 500));

    expect($html)->not->toContain('basic-secret-pw')
        ->and($html)->not->toContain('basic-user-name')
        ->and($html)->toContain('[REDACTED]');
});

test('the Cookies tab masks sensitive cookie values (mutation-checked)', function () {
    // Security #1: a cookie carrying a session/secret value must be masked.
    $request = Request::create('/x', 'GET', [], ['session' => 'cookie-topsecret-abc']);

    $html = stripSourcePanes((new DebugPage())->render(new \RuntimeException('x'), $request, 500));

    expect($html)->not->toContain('cookie-topsecret-abc')
        ->and($html)->toContain('[REDACTED]');
});

test('every cookie value is masked even when the cookie name is not sensitive (mutation-checked)', function () {
    // Security: cookies are overwhelmingly credentials (session ids, remember-me,
    // CSRF). A non-sensitive NAME like "sid" must still have its VALUE masked —
    // only the all-values blanket mask catches this; key-based redaction misses it.
    // Build the secret at runtime so the literal isn't in this test's own source
    // (which would otherwise echo back through a frame excerpt).
    $secret = base64_decode('Q0tfTEVBS183Nzc=', true); // "CK_LEAK_777"
    $request = Request::create('/x', 'GET', [], ['sid' => $secret]);

    $html = stripSourcePanes((new DebugPage())->render(new \RuntimeException('x'), $request, 500));

    expect($html)->not->toContain($secret)
        ->and($html)->toContain('sid')          // the table still lists the cookie name
        ->and($html)->toContain('[REDACTED]');  // value is masked
});

test('the Session tab masks the framework CSRF token (mutation-checked)', function () {
    // Security: the framework stores its CSRF token under a "_csrf/<id>" session
    // key; that token must be masked in the Session tab.
    $secret = base64_decode('Q1NSRl9MRUFLXzk5OQ==', true); // "CSRF_LEAK_999"
    $session = new \Symfony\Component\HttpFoundation\Session\Session(
        new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage(),
    );
    $session->set('_csrf/web', $secret);
    $session->set('auth_user_id', 'visible-user-id');

    $request = Request::create('/x');
    $request->setSession($session);

    $html = stripSourcePanes((new DebugPage())->render(new \RuntimeException('x'), $request, 500));

    expect($html)->not->toContain($secret)
        ->and($html)->toContain('[REDACTED]')
        ->and($html)->toContain('visible-user-id'); // non-sensitive session keys still show
});

test('the Session tab masks sensitive session values (mutation-checked)', function () {
    // Security #1: api_key / token / secret in the session must be masked.
    $session = new \Symfony\Component\HttpFoundation\Session\Session(
        new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage(),
    );
    $session->set('api_key', 'session-topsecret-key');
    $session->set('username', 'visible-session-user');

    $request = Request::create('/x');
    $request->setSession($session);

    $html = stripSourcePanes((new DebugPage())->render(new \RuntimeException('x'), $request, 500));

    expect($html)->not->toContain('session-topsecret-key')
        ->and($html)->toContain('[REDACTED]')
        ->and($html)->toContain('visible-session-user');
});

test('no-session request renders the Session tab without error', function () {
    $request = Request::create('/x');

    $html = (new DebugPage())->render(new \RuntimeException('x'), $request, 500);

    expect($html)->toBeString()
        ->and($html)->toContain('Session');
});

test('the getPrevious() chain is rendered', function () {
    $content = Kernel::handle(Request::create('/boom-chained'))->getContent();

    expect($content)->toContain('LogicException')
        ->and($content)->toContain('root cause detail');
});

test('the previous chain is capped at MAX_CHAIN entries', function () {
    $e = null;
    for ($i = 7; $i >= 1; $i--) {
        $e = new \LogicException('chain-msg-' . $i, 0, $e);
    }
    $outer = new \RuntimeException('outer-top', 0, $e);

    $html = stripSourcePanes((new DebugPage())->render($outer, Request::create('/x'), 500));

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

    $html = stripSourcePanes((new DebugPage())->render($caught, Request::create('/x'), 500));

    expect($html)->toContain('more frames');
});

test('a missing source file does not break rendering', function () {
    $caught = null;
    try {
        eval("throw new \\RuntimeException('eval-thrown');");
    } catch (\Throwable $e) {
        $caught = $e;
    }

    $html = (new DebugPage())->render($caught, Request::create('/x'), 500);

    expect($html)->toContain('RuntimeException')
        ->and($html)->toContain('eval-thrown');
});

test('debug page never dumps env vars or config', function () {
    // Render directly (not via Kernel::handle, which would put THIS test file —
    // and the APP_KEY literal in its own assertion — into the rendered trace).
    $html = stripSourcePanes((new DebugPage())->render(new \RuntimeException('x'), Request::create('/x'), 500));

    // APP_KEY is in the fixture .env; it must never appear via tabs/context.
    expect($html)->not->toContain('0123456789abcdef0123456789abcdef');
});

test('the debug page inlines all assets — no external link or script src', function () {
    $html = (new DebugPage())->render(new \RuntimeException('x'), Request::create('/x'), 500);

    expect($html)->not->toContain('<link ')
        ->and($html)->not->toContain('<script src')
        ->and($html)->toContain('<style>')
        ->and($html)->toContain('<script>');
});

test('the no-JS fallback shows the throw excerpt and the full trace server-side', function () {
    $content = Kernel::handle(Request::create('/boom'))->getContent();

    // The throw-frame excerpt is rendered (highlighted, not hidden) and the
    // frame list is present in the raw HTML, not behind JS.
    expect($content)->toContain('line-err')
        ->and($content)->toContain('ion-code')
        ->and($content)->toContain('frame');
});

test('the page carries a Copy as Markdown action', function () {
    $html = stripSourcePanes((new DebugPage())->render(new \RuntimeException('x'), Request::create('/x'), 500));

    expect($html)->toContain('Copy');
});

test('open-in-editor: header links to the configured editor URI', function () {
    config(['app.debug.editor' => 'vscode']);

    $html = (new DebugPage())->render(new \RuntimeException('x'), Request::create('/x'), 500);

    expect($html)->toContain('vscode://file/');
});

test('open-in-editor: no editor link when unconfigured', function () {
    config(['app.debug.editor' => null]);

    // Strip source panes: a frame's excerpt may legitimately render a source
    // file that mentions an editor scheme; we assert no editor *anchor* is built.
    $html = stripSourcePanes((new DebugPage())->render(new \RuntimeException('x'), Request::create('/x'), 500));

    expect($html)->not->toContain('href="vscode://')
        ->and($html)->not->toContain('href="phpstorm://')
        ->and($html)->not->toContain('class="ion-link edit"');
});

test('JSON requests still get JSON (debug page is HTML-only)', function () {
    $request = Request::create('/boom', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $response = Kernel::handle($request);

    expect($response->headers->get('Content-Type'))->toContain('json');
    $payload = json_decode((string) $response->getContent(), true);
    expect($payload)->toMatchArray(['status' => 'error'])
        ->and((string) $response->getContent())->not->toContain('<html');
});
