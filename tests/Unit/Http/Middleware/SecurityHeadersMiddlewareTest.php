<?php

use Ions\Http\Middleware\SecurityHeadersMiddleware;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

test('applies hardening headers to the downstream response', function () {
    bootFixtureKernel();
    $mw = new SecurityHeadersMiddleware();
    $res = $mw->handle(Request::create('/'), fn ($r) => new Response('ok'));
    expect($res->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($res->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
});
