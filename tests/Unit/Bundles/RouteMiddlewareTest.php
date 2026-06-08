<?php

use Ions\Bundles\Route;
use Ions\Foundation\Kernel;
use Symfony\Component\HttpFoundation\Response;

beforeEach(fn () => bootFixtureKernel());

function routeByPath(string $path): ?\Symfony\Component\Routing\Route
{
    foreach (Kernel::RouteCollection()->all() as $r) {
        if ($r->getPath() === $path) {
            return $r;
        }
    }
    return null;
}

test('Route::middleware records middleware names on the route options', function () {
    Route::get('/guarded', fn () => new Response('x'))->middleware(['my-mw', 'other-mw']);
    $route = routeByPath('/guarded');
    expect($route)->not->toBeNull()
        ->and($route->getOption('middleware'))->toBe(['my-mw', 'other-mw']);
});

test('a route without middleware() has no middleware option (or empty)', function () {
    Route::get('/plain', fn () => new Response('x'));
    $route = routeByPath('/plain');
    expect($route->getOption('middleware') ?? [])->toBe([]);
});
