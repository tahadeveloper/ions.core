<?php

use Ions\Bundles\Route;
use Ions\Foundation\Kernel;
use Ions\Http\Middleware\MiddlewareInterface;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

class HeaderStampMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Route-Mw', 'ran');
        return $response;
    }
}

beforeEach(fn () => bootFixtureKernel());

test('route-level middleware runs in the pipeline for that route', function () {
    Route::get('/with-mw', fn () => new Response('ok'))->middleware([HeaderStampMiddleware::class]);
    $response = Kernel::handle(Request::create('/with-mw'));
    expect($response->getContent())->toBe('ok')
        ->and($response->headers->get('X-Route-Mw'))->toBe('ran');
});

// ---------------------------------------------------------------------------
// resolveMiddleware policy — per-route middleware FAILS CLOSED in every mode.
// An explicitly attached middleware (->middleware([...])) is usually a security
// gate; dropping it would serve the route unprotected.
// ---------------------------------------------------------------------------

test('resolveMiddleware returns 500 with error detail in debug mode when middleware class does not exist', function () {
    putenv('APP_DEBUG=true');
    $_ENV['APP_DEBUG'] = 'true';

    try {
        Route::get('/mw-fail-debug', fn () => new Response('ok'))->middleware(['NonExistentMiddlewareAlias']);
        // In debug mode the InvalidArgumentException propagates and the exception
        // handler renders it as a 500 with the error message in the body.
        $response = Kernel::handle(Request::create('/mw-fail-debug'));
        expect($response->getStatusCode())->toBe(500)
            ->and($response->getContent())->toContain('NonExistentMiddlewareAlias');
    } finally {
        putenv('APP_DEBUG');
        unset($_ENV['APP_DEBUG']);
    }
});

test('unresolvable per-route middleware fails closed with 500 in production (never silently dropped)', function () {
    $originalDebug = getenv('APP_DEBUG');
    putenv('APP_DEBUG=false');
    $_ENV['APP_DEBUG'] = 'false';

    try {
        // Register the route with an unresolvable middleware name.
        Route::get('/mw-prod-fail', fn () => new Response('ok'))->middleware(['NoSuchMiddlewareClass']);
        // The route must NOT be reachable: serving it without its declared
        // middleware would fail open. Expect a 500 with a generic body (no
        // class-name leak in production).
        $response = Kernel::handle(Request::create('/mw-prod-fail'));
        expect($response->getStatusCode())->toBe(500)
            ->and($response->getContent())->not->toContain('NoSuchMiddlewareClass');
    } finally {
        if ($originalDebug === false) {
            putenv('APP_DEBUG');
            unset($_ENV['APP_DEBUG']);
        } else {
            putenv('APP_DEBUG=' . $originalDebug);
            $_ENV['APP_DEBUG'] = $originalDebug;
        }
    }
});
