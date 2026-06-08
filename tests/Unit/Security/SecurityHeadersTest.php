<?php

use Ions\Security\SecurityHeaders;
use Symfony\Component\HttpFoundation\Response;

test('applies hardening headers', function () {
    bootFixtureKernel(); // so config() is available for the CSP default
    $r = SecurityHeaders::apply(new Response('ok'));
    expect($r->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($r->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($r->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($r->headers->get('X-XSS-Protection'))->toBe('0')
        ->and($r->headers->get('Content-Security-Policy'))->toBe("default-src 'self'");
});

test('does not overwrite a CSP already set by the caller', function () {
    bootFixtureKernel();
    $r = new Response('ok');
    $r->headers->set('Content-Security-Policy', "default-src 'none'");
    SecurityHeaders::apply($r);
    expect($r->headers->get('Content-Security-Policy'))->toBe("default-src 'none'");
});
