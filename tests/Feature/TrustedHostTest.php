<?php

use Ions\Support\Request;

afterEach(function () {
    Request::setTrustedHosts([]); // reset global state
});

test('accepts a trusted host and rejects an untrusted one', function () {
    Request::setTrustedHosts(['^localhost$']); // NO braces — Symfony wraps with {…}i itself

    $ok = Request::create('http://localhost/');
    expect($ok->getHost())->toBe('localhost'); // trusted host is accepted

    $bad = Request::create('http://localhost/');
    $bad->headers->set('HOST', 'evil.example.com');
    expect(fn () => $bad->getHost())
        ->toThrow(\Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException::class);
});

test('allows any host when no trusted hosts are configured', function () {
    Request::setTrustedHosts([]);
    $req = Request::create('http://anything.test/');
    expect($req->getHost())->toBe('anything.test');
});
