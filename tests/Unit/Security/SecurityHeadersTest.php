<?php

use Ions\Security\SecurityHeaders;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

test('applies hardening headers', function () {
    bootFixtureKernel(); // so config() is available for the CSP default
    $r = SecurityHeaders::apply(new Response('ok'));
    expect($r->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($r->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($r->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($r->headers->get('X-XSS-Protection'))->toBe('0')
        // Default CSP permits inline STYLES so the framework's self-contained
        // inline-styled pages (welcome, error pages, debug page/toolbar) render.
        ->and($r->headers->get('Content-Security-Policy'))->toContain("default-src 'self'")
        ->and($r->headers->get('Content-Security-Policy'))->toContain("style-src 'self' 'unsafe-inline'");
});

test('default CSP allows inline script only in debug', function () {
    bootFixtureKernel();

    $prev = $_ENV['APP_DEBUG'] ?? null;

    $_ENV['APP_DEBUG'] = 'false';
    putenv('APP_DEBUG=false');
    $prod = SecurityHeaders::apply(new Response('ok'))->headers->get('Content-Security-Policy');

    $_ENV['APP_DEBUG'] = 'true';
    putenv('APP_DEBUG=true');
    $debug = SecurityHeaders::apply(new Response('ok'))->headers->get('Content-Security-Policy');

    // Restore.
    if ($prev === null) {
        unset($_ENV['APP_DEBUG']);
        putenv('APP_DEBUG');
    } else {
        $_ENV['APP_DEBUG'] = $prev;
        putenv('APP_DEBUG=' . $prev);
    }

    expect($prod)->not->toContain("script-src")          // production: inline script blocked
        ->and($debug)->toContain("script-src 'self' 'unsafe-inline'"); // debug: allowed
});

test('does not overwrite a CSP already set by the caller', function () {
    bootFixtureKernel();
    $r = new Response('ok');
    $r->headers->set('Content-Security-Policy', "default-src 'none'");
    SecurityHeaders::apply($r);
    expect($r->headers->get('Content-Security-Policy'))->toBe("default-src 'none'");
});

test('HSTS is set on HTTPS requests with the default policy', function () {
    bootFixtureKernel();
    $r = SecurityHeaders::apply(new Response('ok'), Request::create('https://localhost/x'));
    expect($r->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains');
});

test('HSTS is NOT set on plain-HTTP requests nor when no request is available', function () {
    bootFixtureKernel();
    $http = SecurityHeaders::apply(new Response('ok'), Request::create('http://localhost/x'));
    $none = SecurityHeaders::apply(new Response('ok'));
    expect($http->headers->has('Strict-Transport-Security'))->toBeFalse()
        ->and($none->headers->has('Strict-Transport-Security'))->toBeFalse();
});

test('app.security.hsts overrides the HSTS value and false disables it', function () {
    bootFixtureKernel();
    config(['app.security.hsts' => 'max-age=600']);
    $r = SecurityHeaders::apply(new Response('ok'), Request::create('https://localhost/x'));
    expect($r->headers->get('Strict-Transport-Security'))->toBe('max-age=600');

    config(['app.security.hsts' => false]);
    $r2 = SecurityHeaders::apply(new Response('ok'), Request::create('https://localhost/x'));
    expect($r2->headers->has('Strict-Transport-Security'))->toBeFalse();
});

test('a caller-set HSTS header is not overwritten', function () {
    bootFixtureKernel();
    $r = new Response('ok');
    $r->headers->set('Strict-Transport-Security', 'max-age=1');
    SecurityHeaders::apply($r, Request::create('https://localhost/x'));
    expect($r->headers->get('Strict-Transport-Security'))->toBe('max-age=1');
});

test('Permissions-Policy is set by default (restrictive) on any request', function () {
    bootFixtureKernel();
    $r = SecurityHeaders::apply(new Response('ok'));
    expect($r->headers->get('Permissions-Policy'))->toBe('camera=(), geolocation=(), microphone=()');
});

test('app.security.permissions_policy overrides the value and false disables it', function () {
    bootFixtureKernel();
    config(['app.security.permissions_policy' => 'camera=(self)']);
    $r = SecurityHeaders::apply(new Response('ok'));
    expect($r->headers->get('Permissions-Policy'))->toBe('camera=(self)');

    config(['app.security.permissions_policy' => false]);
    $r2 = SecurityHeaders::apply(new Response('ok'));
    expect($r2->headers->has('Permissions-Policy'))->toBeFalse();
});

test('a caller-set Permissions-Policy header is not overwritten', function () {
    bootFixtureKernel();
    $r = new Response('ok');
    $r->headers->set('Permissions-Policy', 'fullscreen=()');
    SecurityHeaders::apply($r);
    expect($r->headers->get('Permissions-Policy'))->toBe('fullscreen=()');
});

test('the middleware threads the request through so HTTPS responses get HSTS', function () {
    bootFixtureKernel();
    $mw = new \Ions\Http\Middleware\SecurityHeadersMiddleware();
    $res = $mw->handle(Request::create('https://localhost/x'), fn ($r) => new Response('ok'));
    expect($res->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains')
        ->and($res->headers->get('Permissions-Policy'))->toBe('camera=(), geolocation=(), microphone=()');
});
