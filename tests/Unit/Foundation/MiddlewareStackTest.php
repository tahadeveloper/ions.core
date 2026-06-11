<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\Foundation\MiddlewareStack;
use Ions\Http\Middleware\MiddlewareInterface;
use Ions\Http\Middleware\SecurityHeadersMiddleware;

/*
|--------------------------------------------------------------------------
| MiddlewareStack collaborator (Phase 12.7 extraction)
|--------------------------------------------------------------------------
| Pure unit coverage for the helper extracted out of Kernel
| (defaultMiddleware/resolveMiddleware). End-to-end pipeline behavior and the
| fail-closed per-route policy are already proven by the Feature suites; here
| we drive the collaborator directly.
*/

beforeEach(function () {
    bootFixtureKernel();
});

test('defaults() returns web and api stacks of MiddlewareInterface instances', function () {
    $stacks = MiddlewareStack::defaults(Kernel::app());

    expect($stacks)->toHaveKeys(['web', 'api'])
        ->and($stacks['web'])->each->toBeInstanceOf(MiddlewareInterface::class)
        ->and($stacks['api'])->each->toBeInstanceOf(MiddlewareInterface::class);
});

test('resolve() returns a MiddlewareInterface for a valid FQCN', function () {
    $instance = MiddlewareStack::resolve(Kernel::app(), SecurityHeadersMiddleware::class);

    expect($instance)->toBeInstanceOf(SecurityHeadersMiddleware::class);
});

test('resolve() honors a configured middleware alias', function () {
    config(['app.middleware_aliases' => ['sec' => SecurityHeadersMiddleware::class]]);

    expect(MiddlewareStack::resolve(Kernel::app(), 'sec'))
        ->toBeInstanceOf(SecurityHeadersMiddleware::class);
});

test('resolve() fails closed when the class does not exist', function () {
    MiddlewareStack::resolve(Kernel::app(), 'Ions\\NoSuch\\Middleware');
})->throws(InvalidArgumentException::class, 'could not be resolved');

test('resolve() fails closed when the class is not a MiddlewareInterface', function () {
    MiddlewareStack::resolve(Kernel::app(), \stdClass::class);
})->throws(InvalidArgumentException::class, 'does not implement MiddlewareInterface');
