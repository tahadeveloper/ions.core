<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\Http\Middleware\Pipeline;
use Ions\Http\Middleware\Psr15Adapter;
use Ions\Support\Request;
use IonsFixture\Middleware\FixturePsr15Middleware;
use IonsFixture\Middleware\FixturePsr15ShortCircuit;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| PSR-15 adapter (Phase 10.8)
|--------------------------------------------------------------------------
| Psr15Adapter runs any PSR-15 middleware inside the Ions pipeline:
| Symfony Request -> PSR-7 ServerRequest -> vendor middleware -> PSR-7
| Response -> Symfony Response. Request mutations (withAttribute) must reach
| the terminal/controller; response mutations (withHeader) must survive; a
| short-circuiting PSR-15 middleware must skip the terminal entirely.
|
| Host wiring pattern under test: middleware_aliases entries point at
| Psr15Adapter SUBCLASSES that pin the wrapped middleware in their ctor
| (aliases are container-made with no arguments) — see FixturePsr15Alias.
*/

beforeEach(function () {
    bootFixtureKernel();
    FixturePsr15ShortCircuit::$terminalRan = false;
});

test('a real PSR-15 middleware runs in the pipeline: request attribute reaches the controller, response header survives', function () {
    $response = Kernel::handle(Request::create('/psr15/echo'));

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getContent())->toBe('attr:seen-by-psr15')
        ->and($response->headers->get('X-Psr15'))->toBe('applied');
});

test('a short-circuiting PSR-15 middleware returns its own response and the terminal never runs', function () {
    $response = Kernel::handle(Request::create('/psr15/short'));

    expect($response->getStatusCode())->toBe(418)
        ->and((string) $response->getContent())->toBe('short-circuited')
        ->and($response->headers->get('X-Short-Circuit'))->toBe('yes')
        ->and(FixturePsr15ShortCircuit::$terminalRan)->toBeFalse();
});

test('the adapter accepts a PSR-15 instance directly', function () {
    $adapter = new Psr15Adapter(new FixturePsr15Middleware());

    $terminal = fn (Request $r): Response => new Response('got:' . $r->attributes->get('psr15_note', 'missing'));
    $response = (new Pipeline([$adapter], $terminal))->handle(Request::create('/anything'));

    expect((string) $response->getContent())->toBe('got:seen-by-psr15')
        ->and($response->headers->get('X-Psr15'))->toBe('applied');
});

test('the adapter accepts a class-string resolved through the container at handle time', function () {
    $adapter = new Psr15Adapter(FixturePsr15Middleware::class);

    $terminal = fn (Request $r): Response => new Response('got:' . $r->attributes->get('psr15_note', 'missing'));
    $response = (new Pipeline([$adapter], $terminal))->handle(Request::create('/anything'));

    expect((string) $response->getContent())->toBe('got:seen-by-psr15')
        ->and($response->headers->get('X-Psr15'))->toBe('applied');
});

test('a class that is not PSR-15 middleware fails closed with a clear error', function () {
    $adapter = new Psr15Adapter(\stdClass::class);

    expect(fn () => $adapter->handle(Request::create('/x'), fn () => new Response('never')))
        ->toThrow(InvalidArgumentException::class, 'Psr\Http\Server\MiddlewareInterface');
});

test('query string and method survive the PSR-7 round trip into the terminal', function () {
    $adapter = new Psr15Adapter(new FixturePsr15Middleware());

    $terminal = fn (Request $r): Response => new Response($r->getMethod() . ':' . $r->query->get('q'));
    $response = (new Pipeline([$adapter], $terminal))->handle(Request::create('/search?q=ions', 'POST'));

    expect((string) $response->getContent())->toBe('POST:ions');
});
