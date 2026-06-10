<?php

use Ions\Foundation\Kernel;
use Ions\Support\Request;

beforeEach(fn () => bootFixtureKernel());

test('abort(403) inside a route renders a 403 Response via the handler (no exit)', function () {
    $response = Kernel::handle(Request::create('/forbidden'));
    expect($response->getStatusCode())->toBe(403);
});

test('a generic exception inside a route renders a 500 Response and does not leak the message (prod)', function () {
    $response = Kernel::handle(Request::create('/boom'));
    expect($response->getStatusCode())->toBe(500)
        ->and($response->getContent())->not->toContain('SENSITIVE');
});

test('production 500 output keeps the exact minimal shape (no debug page leak)', function () {
    // fixture .env has no APP_DEBUG → production rendering. This pins the
    // pre-4.1 byte-for-byte shape so the rich debug page can never leak here.
    $response = Kernel::handle(Request::create('/boom'));
    expect($response->getContent())->toBe('<h1>500 Internal Server Error</h1>');
});

test('an unmatched route still renders a 404 Response', function () {
    $response = Kernel::handle(Request::create('/no-such-route'));
    expect($response->getStatusCode())->toBe(404);
});
