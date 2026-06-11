<?php

use Ions\Bundles\Route;
use Ions\Foundation\Kernel;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    bootFixtureKernel();
    putenv('APP_DEBUG');
    unset($_ENV['APP_DEBUG']);
});

test('a route behind the cache.response alias serves the 2nd hit from cache', function () {
    $hits = 0;
    Route::get('/cached-page', function () use (&$hits) {
        $hits++;
        return new Response('rendered-' . $hits, 200, ['Content-Type' => 'text/html']);
    })->middleware(['cache.response']);

    $first = Kernel::handle(Request::create('/cached-page'));
    expect($first->getStatusCode())->toBe(200)
        ->and($first->getContent())->toBe('rendered-1')
        ->and($first->headers->get('X-Ions-Cache'))->toBe('MISS');

    $second = Kernel::handle(Request::create('/cached-page'));
    expect($second->headers->get('X-Ions-Cache'))->toBe('HIT')
        ->and($second->getContent())->toBe('rendered-1') // identical body, not re-rendered
        ->and($hits)->toBe(1); // controller ran exactly once
});

test('cached route honours a conditional request with 304', function () {
    Route::get('/cached-etag', fn () => new Response('etag-body', 200))
        ->middleware(['cache.response']);

    $first = Kernel::handle(Request::create('/cached-etag'));
    $etag = $first->getEtag();
    expect($etag)->not->toBeNull();

    $second = Kernel::handle(
        Request::create('/cached-etag', 'GET', server: ['HTTP_IF_NONE_MATCH' => $etag])
    );
    expect($second->getStatusCode())->toBe(304)
        ->and($second->getContent())->toBe('');
});

test('cache.response never caches a non-200 response', function () {
    $hits = 0;
    Route::get('/cached-500', function () use (&$hits) {
        $hits++;
        return new Response('boom', 500);
    })->middleware(['cache.response']);

    Kernel::handle(Request::create('/cached-500'));
    $second = Kernel::handle(Request::create('/cached-500'));

    expect($second->headers->get('X-Ions-Cache'))->toBeNull()
        ->and($hits)->toBe(2); // re-rendered every time
});
