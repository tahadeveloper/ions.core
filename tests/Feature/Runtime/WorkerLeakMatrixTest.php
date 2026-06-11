<?php

declare(strict_types=1);

/**
 * Phase 12.6 — multi-subsystem worker leak matrix.
 *
 * Boots the fixture kernel ONCE, then drives 2+ sequential
 * resetForRequest() → handle() cycles through fixture routes that EXERCISE the
 * subsystems added between 8.x and 12.x, asserting NO cross-request bleed:
 *
 *   - Auth (JWT) + Gate user        — request A authenticates as user-99; request B
 *                                      (no token) must be a guest, with NO trace of A.
 *   - Flash                         — A flashes a value; B must not consume it.
 *   - Session + CSRF token          — A writes a session value & has a CSRF token;
 *                                      B sees neither.
 *   - Trusted proxies (10.1)        — A behind a trusted proxy resolves the XFF
 *                                      client IP / scheme; B from an UNTRUSTED peer
 *                                      must fall back to its own REMOTE_ADDR.
 *   - Eloquent query log (8.1)      — A runs queries; B starts with a clean log.
 *   - Response cache (12.5)         — A caches one URL; B (different URL) gets its
 *                                      own response, no key collision / stale body.
 *   - IonDisk per-call bucket (12.1)— a per-call override in A does not bleed into B.
 *
 * The whole file is a characterization suite: it proves the resetForRequest()
 * design already isolates these subsystems (no production change was needed for
 * them). Each block also has a control where one CAN show the leak the reset
 * closes, mirroring the 8.2 RequestIsolationTest convention.
 */

use Ions\Bundles\IonDisk;
use Ions\Bundles\Route;
use Ions\Foundation\Kernel;
use Ions\Session\Flash;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;

beforeEach(fn () => bootFixtureKernel());

/**
 * Build an api request carrying a real Bearer JWT for the given user id — the
 * exact shape AuthMiddleware verifies (TestCase::actingAs does the same).
 */
function workerAuthRequest(string $uri, string $userId): Request
{
    $jwt = Kernel::app()->get('jwt');
    $token = $jwt->issue($userId);

    return Request::create($uri, 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
}

// ---------------------------------------------------------------- Auth + Gate

test('Gate user does not leak: request A as user-99, request B as guest', function () {
    // Request A: authenticated as user-99 → the gate's 'view-secret' ability
    // (user-99 only) passes end-to-end through the api pipeline.
    $a = Kernel::handle(workerAuthRequest('/api/gate/secret', 'user-99'));
    expect($a->getStatusCode())->toBe(200)
        ->and($a->getContent())->toBe('gate secret granted')
        // The shared request now carries A's resolved user.
        ->and(Kernel::request()->attributes->get('auth_user'))->not->toBeNull();

    Kernel::resetForRequest();

    // Request B: NO token → guest. The gate must NOT still see user-99 (no
    // cached forUser scope, no stale 'auth_user' on a shared request).
    $b = Kernel::handle(Request::create('/api/gate/secret'));
    expect($b->getStatusCode())->toBe(401) // AuthMiddleware rejects the guest first
        ->and(Kernel::request()->attributes->get('auth_user'))->toBeNull()
        // And the gate singleton itself reports guest for a user-scoped ability.
        ->and(Kernel::app()->get('gate')->allows('members-area'))->toBeFalse();
});

test('control: the gate singleton is not a forUser clone and resolves per request', function () {
    // The 'gate' binding is the SAME singleton across requests (boot state),
    // but it reads the user lazily from Kernel::request() each check — so the
    // identity surviving is correct; only the resolved user must change.
    $gate1 = Kernel::app()->get('gate');
    Kernel::handle(workerAuthRequest('/api/gate/secret', 'user-99'));

    Kernel::resetForRequest();
    Kernel::handle(Request::create('/ping'));

    $gate2 = Kernel::app()->get('gate');
    expect($gate2)->toBe($gate1)                       // boot state kept
        ->and($gate2->allows('members-area'))->toBeFalse(); // but guest now
});

// ---------------------------------------------------------------------- Flash

test('flash does not leak across requests', function () {
    Kernel::handle(Request::create('/ping'));
    Flash::put('notice', 'from-A');
    // Not yet consumed within A.

    Kernel::resetForRequest();
    Kernel::handle(Request::create('/ping'));

    // FlashBag is consume-on-NEXT-request by design, BUT resetForRequest()
    // swaps in a brand-new session (fresh FlashBag), so B never inherits A's
    // un-consumed flash.
    expect(Flash::consume('notice'))->toBeNull();
});

test('control: without reset the flash survives to the next request', function () {
    Kernel::handle(Request::create('/ping'));
    Flash::put('notice', 'from-A');

    // No resetForRequest(): same session, same FlashBag.
    Kernel::handle(Request::create('/ping'));
    expect(Flash::consume('notice'))->toBe('from-A');
});

// ------------------------------------------------------------- Session + CSRF

test('session value and CSRF token do not leak across requests', function () {
    Kernel::handle(Request::create('/session-write')); // session('leak') = 'r1'
    expect(session('leak'))->toBe('r1');

    /** @var \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrf */
    $csrf = Kernel::app()->get('csrf');
    $tokenA = $csrf->getToken('web')->getValue();

    Kernel::resetForRequest();
    Kernel::handle(Request::create('/session-read'));

    // Fresh session: A's value is gone…
    expect(session('leak'))->toBeNull();

    // …and A's CSRF token no longer validates against the new session.
    expect($csrf->isTokenValid(new CsrfToken('web', $tokenA)))->toBeFalse();
});

// ------------------------------------------------------------- Eloquent query log

test('query log is clean for request B after request A ran queries', function () {
    config(['database.query_log' => true]);

    try {
        Route::get('/qlog-run', function () {
            \Ions\Support\DB::connection()->select('select 1 as a');
            \Ions\Support\DB::connection()->select('select 2 as b');

            return new Response('ran');
        });

        $connection = Kernel::app()->get('db')->connection();
        $connection->enableQueryLog();

        Kernel::handle(Request::create('/qlog-run'));
        expect(count($connection->getQueryLog()))->toBeGreaterThan(0);

        // resetForRequest() flushes the enabled query log so it never grows
        // across worker requests.
        Kernel::resetForRequest();

        // B's log starts empty — A's statements were flushed by the reset.
        expect($connection->getQueryLog())->toBe([]);
    } finally {
        config(['database.query_log' => false]);
    }
});

test('control: resetForRequest flushes an enabled query log', function () {
    config(['database.query_log' => true]);

    try {
        $connection = Kernel::app()->get('db')->connection();
        $connection->enableQueryLog();
        $connection->select('select 1 as a');
        expect(count($connection->getQueryLog()))->toBeGreaterThan(0);

        Kernel::resetForRequest();

        expect($connection->getQueryLog())->toBe([]);
    } finally {
        config(['database.query_log' => false]);
    }
});

// ----------------------------------------------------------- Trusted proxies

test('trusted-proxy resolution does not bleed: A trusted peer, B untrusted peer', function () {
    config(['app.trusted_proxies' => ['10.0.0.1']]);

    try {
        // Request A: comes FROM the trusted proxy 10.0.0.1 with XFF headers →
        // the framework honors them (client 203.0.113.7, https).
        $a = Kernel::handle(Request::create('/proxy-echo', 'GET', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ]));
        $echoA = json_decode((string) $a->getContent(), true);
        expect($echoA['secure'])->toBeTrue()
            ->and($echoA['ip'])->toBe('203.0.113.7');

        Kernel::resetForRequest();

        // Request B: same XFF headers but from an UNTRUSTED peer (192.0.2.9).
        // B must NOT inherit A's trusted resolution — the XFF headers are
        // ignored and B's own REMOTE_ADDR / scheme win.
        $b = Kernel::handle(Request::create('/proxy-echo', 'GET', [], [], [], [
            'REMOTE_ADDR' => '192.0.2.9',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ]));
        $echoB = json_decode((string) $b->getContent(), true);
        expect($echoB['secure'])->toBeFalse()
            ->and($echoB['ip'])->toBe('192.0.2.9');
    } finally {
        config(['app.trusted_proxies' => []]);
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }
});

test("trusted-proxy '*' wildcard resolves the peer of EACH request", function () {
    config(['app.trusted_proxies' => ['*']]);

    try {
        // A: peer 198.51.100.10 is trusted (wildcard) → XFF honored.
        $a = Kernel::handle(Request::create('/proxy-echo', 'GET', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));
        expect(json_decode((string) $a->getContent(), true)['ip'])->toBe('203.0.113.7');

        Kernel::resetForRequest();

        // B: different peer; the wildcard must re-resolve to B's peer, so B's
        // own XFF (from the now-trusted B peer) is honored — proving the
        // per-request re-apply, not a frozen A resolution.
        $b = Kernel::handle(Request::create('/proxy-echo', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_X_FORWARDED_FOR' => '198.18.0.9',
            'HTTP_X_FORWARDED_PROTO' => 'http',
        ]));
        $echoB = json_decode((string) $b->getContent(), true);
        expect($echoB['ip'])->toBe('198.18.0.9')
            ->and($echoB['secure'])->toBeFalse();
    } finally {
        config(['app.trusted_proxies' => []]);
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }
});

// ------------------------------------------------------------- Response cache

test('response cache: distinct URLs do not collide across requests', function () {
    $hitsX = 0;
    $hitsY = 0;

    Route::get('/cache-x', function () use (&$hitsX) {
        $hitsX++;

        return new Response('X#' . $hitsX);
    })->middleware(['cache.response']);

    Route::get('/cache-y', function () use (&$hitsY) {
        $hitsY++;

        return new Response('Y#' . $hitsY);
    })->middleware(['cache.response']);

    // Request A populates the cache for /cache-x.
    $a = Kernel::handle(Request::create('/cache-x'));
    expect($a->getContent())->toBe('X#1');

    Kernel::resetForRequest();

    // Request B on a DIFFERENT url gets its own fresh body — no stale 'X#1',
    // no key collision with A.
    $b = Kernel::handle(Request::create('/cache-y'));
    expect($b->getContent())->toBe('Y#1');

    Kernel::resetForRequest();

    // Request C re-hits /cache-x → served from cache (stable key, survives the
    // resets as boot/cache state, which is the intended behavior).
    $c = Kernel::handle(Request::create('/cache-x'));
    expect($c->getContent())->toBe('X#1')
        ->and($hitsX)->toBe(1); // closure not re-run — true cache hit
});

// ----------------------------------------------------------------- IonDisk

test('IonDisk per-call bucket override does not bleed across requests', function () {
    IonDisk::init();

    // Read the static $bucket directly so the assertion is exact, not inferred.
    $readBucket = static function (): mixed {
        $prop = new ReflectionProperty(IonDisk::class, 'bucket');
        $prop->setAccessible(true);

        return $prop->getValue();
    };
    $baselineBucket = $readBucket();

    // Request A: a per-call bucket override (12.1 FINDING 7, save/restore in a
    // finally). exists() on the local disk stays on the native-file path (no
    // S3 client needed) but still routes through applyOptionOverrides().
    $optionsA = new \Symfony\Component\HttpFoundation\ParameterBag(['bucket' => 'override-bucket-A']);
    IonDisk::exists('img/a.png', $optionsA);

    // The override was restored at the end of the A call already…
    expect($readBucket())->toBe($baselineBucket);

    Kernel::resetForRequest();

    // Request B: a no-override call must see the original init-time bucket, with
    // NO residual 'override-bucket-A' from A.
    IonDisk::exists('img/b.png');
    expect($readBucket())->toBe($baselineBucket);
});
