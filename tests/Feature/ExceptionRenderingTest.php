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

test('production 500 output renders the branded built-in page with no debug page leak', function () {
    // fixture .env has no APP_DEBUG → production rendering. The 14.4 branded
    // built-in page replaces the pre-4.1 bare <h1>, but the rich debug page
    // (and the exception's internals) must still never leak here.
    $content = (string) Kernel::handle(Request::create('/boom'))->getContent();

    expect($content)
        ->toContain('<!DOCTYPE html>')
        ->toContain('500')
        ->toContain('Server Error')
        ->toContain('Internal Server Error')        // client-safe message
        ->and($content)->not->toContain('SENSITIVE') // generic message never leaks
        ->and($content)->not->toContain('ion-debug') // no debug page
        ->and(stripos($content, '<script'))->toBeFalse();
});

test('an unmatched route still renders a 404 Response', function () {
    $response = Kernel::handle(Request::create('/no-such-route'));
    expect($response->getStatusCode())->toBe(404);
});
