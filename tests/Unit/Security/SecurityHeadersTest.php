<?php

use Ions\Security\SecurityHeaders;
use Symfony\Component\HttpFoundation\Response;

// NOTE: 1.x has no test harness / fixture kernel, so config('app.security.csp')
// cannot be resolved here. We therefore pre-set a CSP on the response (which
// SecurityHeaders must NOT overwrite), which also avoids the kernel-backed
// config() call while still proving the four static hardening headers are set
// and a CSP header is present on the returned response.

test('applies the four static hardening headers and leaves a preset CSP intact', function () {
    $r = new Response('ok');
    $r->headers->set('Content-Security-Policy', "default-src 'self'");

    $out = SecurityHeaders::apply($r);

    expect($out->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($out->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($out->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($out->headers->get('X-XSS-Protection'))->toBe('0')
        ->and($out->headers->has('Content-Security-Policy'))->toBeTrue();
});

test('does not overwrite a CSP already set by the caller', function () {
    $r = new Response('ok');
    $r->headers->set('Content-Security-Policy', "default-src 'none'");

    SecurityHeaders::apply($r);

    expect($r->headers->get('Content-Security-Policy'))->toBe("default-src 'none'");
});
