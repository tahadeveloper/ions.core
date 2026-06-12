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

test('cache.response does NOT cache a flash/errors/old response (cross-user leak)', function () {
    // REGRESSION (CRITICAL-1): an anonymous GET 200 on a cache.response route
    // that writes a personalised flash value (here a per-request token) must NOT
    // be cached — Session::all() misses the FlashBag, so before the fix this
    // response was stored and served verbatim to the NEXT anonymous user.
    $hits = 0;
    Route::get('/cached-flash', function () use (&$hits) {
        $hits++;
        // Personalised state written to the flash bag (errors()/old()/flash()
        // all land here). e.g. a guest validation error echoing an email.
        flash('_ions_errors', ['email' => ["taken: user{$hits}@example.test"]]);

        return new Response("render-{$hits}", 200, ['Content-Type' => 'text/html']);
    })->middleware(['cache.response']);

    $first = Kernel::handle(Request::create('/cached-flash'));
    expect($first->getContent())->toBe('render-1')
        ->and($first->headers->get('X-Ions-Cache'))->not->toBe('MISS'); // not stored

    // A SECOND anonymous user must not be served the first user's flashed body.
    $second = Kernel::handle(Request::create('/cached-flash'));
    expect($second->headers->get('X-Ions-Cache'))->not->toBe('HIT')
        ->and($second->getContent())->toBe('render-2') // freshly rendered
        ->and($hits)->toBe(2); // the controller ran for BOTH users
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

test('a FORM-LESS framework-rendered page is response-cached on the 2nd hit (headline 12.5 case)', function () {
    // Point Twig at the committed fixture views before the first render builds
    // the shared env (mirrors ViewRenderingTest).
    config(['app.twig.source' => \Ions\Bundles\Path::root('views')]);

    $hits = 0;
    Route::get('/cached-view', function () use (&$hits) {
        $hits++;
        return view('cache.public');
    })->middleware(['cache.response']);

    $first = Kernel::handle(Request::create('/cached-view'));
    expect($first->getStatusCode())->toBe(200)
        ->and($first->getContent())->toContain('public page')
        ->and($first->headers->get('X-Ions-Cache'))->toBe('MISS');

    // 2nd hit: served from cache because the lazy _csrf_token global was never
    // stringified by this form-less template, so the session stayed empty.
    $second = Kernel::handle(Request::create('/cached-view'));
    expect($second->headers->get('X-Ions-Cache'))->toBe('HIT')
        ->and($second->getContent())->toContain('public page')
        ->and($hits)->toBe(1); // controller ran exactly once
});

test('a page rendering a FORM via ionToken() is NOT response-cached (CSRF safety preserved)', function () {
    config(['app.twig.source' => \Ions\Bundles\Path::root('views')]);

    $hits = 0;
    Route::get('/cached-form', function () use (&$hits) {
        $hits++;
        return view('cache.form');
    })->middleware(['cache.response']);

    $first = Kernel::handle(Request::create('/cached-form'));
    expect($first->getContent())->toContain('_ion_token')
        ->and($first->headers->get('X-Ions-Cache'))->not->toBe('HIT');

    // 2nd hit re-renders (the per-session token in the session made it stateful).
    $second = Kernel::handle(Request::create('/cached-form'));
    expect($second->headers->get('X-Ions-Cache'))->not->toBe('HIT')
        ->and($hits)->toBe(2);
});

test('a page outputting {{ _csrf_token }} directly is NOT response-cached (CSRF safety preserved)', function () {
    config(['app.twig.source' => \Ions\Bundles\Path::root('views')]);

    $hits = 0;
    Route::get('/cached-token', function () use (&$hits) {
        $hits++;
        return view('cache.token');
    })->middleware(['cache.response']);

    $first = Kernel::handle(Request::create('/cached-token'));
    expect($first->getContent())->toContain('page with token')
        ->and($first->headers->get('X-Ions-Cache'))->not->toBe('HIT');

    $second = Kernel::handle(Request::create('/cached-token'));
    expect($second->headers->get('X-Ions-Cache'))->not->toBe('HIT')
        ->and($hits)->toBe(2);
});
