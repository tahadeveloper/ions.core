<?php

use Ions\Http\Middleware\TrustedHostMiddleware;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

afterEach(fn () => Request::setTrustedHosts([]));

test('accepts a trusted host and rejects an untrusted one via the configured patterns', function () {
    $mw = new TrustedHostMiddleware(['^localhost$']); // no braces
    // terminal reads getHost(), which enforces trusted hosts
    $terminal = fn (Request $r) => new Response($r->getHost());

    $ok = Request::create('http://localhost/');
    expect($mw->handle($ok, $terminal)->getContent())->toBe('localhost');

    $bad = Request::create('http://localhost/');
    $bad->headers->set('HOST', 'evil.example.com');
    expect(fn () => $mw->handle($bad, $terminal))
        ->toThrow(\Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException::class);
});

test('no patterns means no host restriction', function () {
    $mw = new TrustedHostMiddleware([]);
    $req = Request::create('http://anything.test/');
    expect($mw->handle($req, fn ($r) => new Response($r->getHost()))->getContent())->toBe('anything.test');
});
