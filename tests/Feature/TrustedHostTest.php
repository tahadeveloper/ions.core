<?php

use Ions\Support\Request;

afterEach(function () {
    Request::setTrustedHosts([]); // reset global state
});

test('rejects a request whose Host is not in the trusted list', function () {
    Request::setTrustedHosts(['{^localhost$}i']);
    $req = Request::create('http://localhost/');
    $req->headers->set('HOST', 'evil.example.com');
    expect(fn () => $req->getHost())
        ->toThrow(\Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException::class);
});

test('allows any host when no trusted hosts are configured', function () {
    Request::setTrustedHosts([]);
    $req = Request::create('http://anything.test/');
    expect($req->getHost())->toBe('anything.test');
});
