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
