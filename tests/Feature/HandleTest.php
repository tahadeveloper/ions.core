<?php

use Ions\Foundation\Kernel;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

test('handle() runs the web pipeline and returns a Response with hardening headers', function () {
    bootFixtureKernel();
    $response = Kernel::handle(Request::create('/ping'));
    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('pong')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('an unmatched route returns a 404 Response (no exit/die)', function () {
    bootFixtureKernel();
    $response = Kernel::handle(Request::create('/does-not-exist'));
    expect($response->getStatusCode())->toBe(404);
});
