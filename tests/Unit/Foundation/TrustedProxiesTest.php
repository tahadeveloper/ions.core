<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\Foundation\TrustedProxies;
use Ions\Support\Request;

/*
|--------------------------------------------------------------------------
| TrustedProxies collaborator (Phase 12.7 extraction)
|--------------------------------------------------------------------------
| Pure unit coverage for the helper extracted out of Kernel. The end-to-end
| boot/handle behavior is already proven by tests/Feature/Security/
| TrustedProxiesTest.php; here we exercise the collaborator directly.
*/

beforeEach(function () {
    // A booted kernel gives the collaborator a live Config to read.
    bootFixtureKernel();
});

afterEach(function () {
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

test('apply() is a no-op when no trusted proxies are configured', function () {
    config(['app.trusted_proxies' => []]);

    TrustedProxies::apply();

    expect(Request::getTrustedProxies())->toBe([]);
});

test('apply() sets literal proxy entries on the Request class static', function () {
    config(['app.trusted_proxies' => ['10.0.0.1', '192.168.0.0/16']]);

    TrustedProxies::apply();

    expect(Request::getTrustedProxies())->toBe(['10.0.0.1', '192.168.0.0/16']);
});

test("apply() resolves '*' against the given request peer", function () {
    config(['app.trusted_proxies' => ['*']]);

    $request = Request::create('http://localhost/', 'GET', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.42',
    ]);

    TrustedProxies::apply($request);

    expect(Request::getTrustedProxies())->toBe(['203.0.113.42']);
});

test("apply() falls back to Symfony's REMOTE_ADDR token when no peer is available", function () {
    config(['app.trusted_proxies' => ['*']]);

    // No request and no $_SERVER['REMOTE_ADDR']: Symfony drops the unresolved
    // token, leaving an empty trusted list.
    $hadRemoteAddr = array_key_exists('REMOTE_ADDR', $_SERVER);
    $original = $_SERVER['REMOTE_ADDR'] ?? null;
    unset($_SERVER['REMOTE_ADDR']);

    try {
        TrustedProxies::apply();
        expect(Request::getTrustedProxies())->toBe([]);
    } finally {
        if ($hadRemoteAddr) {
            $_SERVER['REMOTE_ADDR'] = $original;
        }
    }
});

test('headerSet() maps friendly names, int passthrough, and throws on unknown', function (mixed $configured, int $expected) {
    config(['app.trusted_proxy_headers' => $configured]);

    expect(TrustedProxies::headerSet())->toBe($expected);
})->with([
    'xff combo' => [
        'xff',
        Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
    ],
    'aws-elb' => ['aws-elb', Request::HEADER_X_FORWARDED_AWS_ELB],
    'traefik' => ['traefik', Request::HEADER_X_FORWARDED_TRAEFIK],
    'forwarded' => ['forwarded', Request::HEADER_FORWARDED],
    'mixed case' => ['Forwarded', Request::HEADER_FORWARDED],
    'int passthrough' => [
        Request::HEADER_X_FORWARDED_FOR,
        Request::HEADER_X_FORWARDED_FOR,
    ],
]);

test('headerSet() throws InvalidArgumentException on an unknown string', function () {
    config(['app.trusted_proxy_headers' => 'aws_elb']);

    TrustedProxies::headerSet();
})->throws(InvalidArgumentException::class, "'aws_elb'");
