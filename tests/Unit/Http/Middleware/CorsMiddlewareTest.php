<?php

use Ions\Http\Middleware\CorsMiddleware;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

test('preflight OPTIONS short-circuits with 204 + CORS headers and does not call next', function () {
    $reached = false;
    $mw = new CorsMiddleware(['origins' => ['*'], 'methods' => ['GET', 'POST', 'OPTIONS']]);
    $res = $mw->handle(Request::create('/api', 'OPTIONS'), function ($r) use (&$reached) {
        $reached = true;
        return new Response('x');
    });
    expect($res->getStatusCode())->toBe(204)
        ->and($res->headers->get('Access-Control-Allow-Methods'))->toContain('POST')
        ->and($reached)->toBeFalse();
});

test('non-preflight request gets Access-Control-Allow-Origin on the downstream response', function () {
    $mw = new CorsMiddleware(['origins' => ['*']]);
    $res = $mw->handle(Request::create('/api', 'GET'), fn ($r) => new Response('ok'));
    expect($res->getContent())->toBe('ok')
        ->and($res->headers->get('Access-Control-Allow-Origin'))->toBe('*');
});

test('does not reflect an untrusted Origin when a specific allow-list is configured', function () {
    $mw = new CorsMiddleware(['origins' => ['https://trusted.example']]);
    $req = Request::create('/api', 'GET');
    $req->headers->set('Origin', 'https://evil.com');
    $res = $mw->handle($req, fn ($r) => new Response('ok'));
    // must NOT echo the evil origin back
    expect($res->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.com');
});

test('reflects an allow-listed Origin when it matches', function () {
    $mw = new CorsMiddleware(['origins' => ['https://trusted.example']]);
    $req = Request::create('/api', 'GET');
    $req->headers->set('Origin', 'https://trusted.example');
    $res = $mw->handle($req, fn ($r) => new Response('ok'));
    expect($res->headers->get('Access-Control-Allow-Origin'))->toBe('https://trusted.example');
});

// ── D8-1 default flip: deny by default ──────────────────────────────────────

test('no config means NO CORS headers are emitted for any origin (deny-by-default)', function () {
    $mw = new CorsMiddleware();
    $req = Request::create('/api', 'GET');
    $req->headers->set('Origin', 'https://anything.example');
    $res = $mw->handle($req, fn ($r) => new Response('ok'));
    expect($res->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and($res->headers->has('Access-Control-Allow-Methods'))->toBeFalse()
        ->and($res->headers->has('Access-Control-Allow-Headers'))->toBeFalse()
        ->and($res->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
});

test('preflight with no configured origins is a plain 204 without CORS headers', function () {
    $mw = new CorsMiddleware();
    $req = Request::create('/api', 'OPTIONS');
    $req->headers->set('Origin', 'https://anything.example');
    $res = $mw->handle($req, fn ($r) => new Response('x'));
    expect($res->getStatusCode())->toBe(204)
        ->and($res->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and($res->headers->has('Access-Control-Max-Age'))->toBeFalse();
});

test('a non-matching Origin against an allow-list gets no CORS headers at all', function () {
    $mw = new CorsMiddleware(['origins' => ['https://trusted.example']]);
    $req = Request::create('/api', 'GET');
    $req->headers->set('Origin', 'https://evil.com');
    $res = $mw->handle($req, fn ($r) => new Response('ok'));
    expect($res->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and($res->headers->has('Access-Control-Allow-Methods'))->toBeFalse();
});

// ── Vary: Origin on reflected origins ────────────────────────────────────────

test('reflecting an allow-listed Origin adds Vary: Origin', function () {
    $mw = new CorsMiddleware(['origins' => ['https://trusted.example']]);
    $req = Request::create('/api', 'GET');
    $req->headers->set('Origin', 'https://trusted.example');
    $res = $mw->handle($req, fn ($r) => new Response('ok'));
    expect($res->getVary())->toContain('Origin');
});

test('preflight reflection also adds Vary: Origin', function () {
    $mw = new CorsMiddleware(['origins' => ['https://trusted.example']]);
    $req = Request::create('/api', 'OPTIONS');
    $req->headers->set('Origin', 'https://trusted.example');
    $res = $mw->handle($req, fn ($r) => new Response('x'));
    expect($res->getStatusCode())->toBe(204)
        ->and($res->getVary())->toContain('Origin');
});

test('the wildcard origin does NOT add Vary: Origin', function () {
    $mw = new CorsMiddleware(['origins' => ['*']]);
    $req = Request::create('/api', 'GET');
    $req->headers->set('Origin', 'https://anything.example');
    $res = $mw->handle($req, fn ($r) => new Response('ok'));
    expect($res->headers->get('Access-Control-Allow-Origin'))->toBe('*')
        ->and($res->getVary())->not->toContain('Origin');
});

test('no Vary is added when no CORS headers are emitted', function () {
    $denyAll = new CorsMiddleware();
    $req = Request::create('/api', 'GET');
    $req->headers->set('Origin', 'https://anything.example');
    $res = $denyAll->handle($req, fn ($r) => new Response('ok'));
    expect($res->getVary())->not->toContain('Origin');

    $allowList = new CorsMiddleware(['origins' => ['https://trusted.example']]);
    $req = Request::create('/api', 'GET');
    $req->headers->set('Origin', 'https://evil.com');
    $res = $allowList->handle($req, fn ($r) => new Response('ok'));
    expect($res->getVary())->not->toContain('Origin');
});

test('an existing Vary header is appended to, not clobbered', function () {
    $mw = new CorsMiddleware(['origins' => ['https://trusted.example']]);
    $req = Request::create('/api', 'GET');
    $req->headers->set('Origin', 'https://trusted.example');
    $res = $mw->handle($req, function ($r) {
        $downstream = new Response('ok');
        $downstream->headers->set('Vary', 'Accept-Encoding');
        return $downstream;
    });
    expect($res->getVary())->toContain('Accept-Encoding')
        ->and($res->getVary())->toContain('Origin');
});

test('Origin is not duplicated when Vary already contains it', function () {
    $mw = new CorsMiddleware(['origins' => ['https://trusted.example']]);
    $req = Request::create('/api', 'GET');
    $req->headers->set('Origin', 'https://trusted.example');
    $res = $mw->handle($req, function ($r) {
        $downstream = new Response('ok');
        $downstream->headers->set('Vary', 'Accept-Encoding, Origin');
        return $downstream;
    });
    $origins = array_filter($res->getVary(), fn ($v) => strcasecmp($v, 'Origin') === 0);
    expect($origins)->toHaveCount(1);
});

// ── Credentials rules ────────────────────────────────────────────────────────

test('credentials header is emitted only when explicitly enabled with a specific origin', function () {
    $mw = new CorsMiddleware([
        'origins' => ['https://trusted.example'],
        'credentials' => true,
    ]);
    $req = Request::create('/api', 'GET');
    $req->headers->set('Origin', 'https://trusted.example');
    $res = $mw->handle($req, fn ($r) => new Response('ok'));
    expect($res->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
});

test('credentials header is NOT emitted with the wildcard origin (spec violation)', function () {
    $mw = new CorsMiddleware(['origins' => ['*'], 'credentials' => true]);
    $req = Request::create('/api', 'GET');
    $req->headers->set('Origin', 'https://trusted.example');
    $res = $mw->handle($req, fn ($r) => new Response('ok'));
    expect($res->headers->get('Access-Control-Allow-Origin'))->toBe('*')
        ->and($res->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
});

test('credentials header is NOT emitted when not explicitly enabled', function () {
    $mw = new CorsMiddleware(['origins' => ['https://trusted.example']]);
    $req = Request::create('/api', 'GET');
    $req->headers->set('Origin', 'https://trusted.example');
    $res = $mw->handle($req, fn ($r) => new Response('ok'));
    expect($res->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
});
