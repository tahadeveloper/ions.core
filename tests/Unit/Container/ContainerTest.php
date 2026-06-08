<?php

use Ions\Container\Container;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

test('is a PSR-11 container', function () {
    expect(new Container())->toBeInstanceOf(ContainerInterface::class);
});

test('binds and resolves a factory', function () {
    $c = new Container();
    $c->bind('greeting', fn () => 'hi');
    expect($c->get('greeting'))->toBe('hi');
});

test('singleton returns the same instance', function () {
    $c = new Container();
    $c->singleton('svc', fn () => new stdClass());
    expect($c->get('svc'))->toBe($c->get('svc'));
});

test('has() reflects bindings', function () {
    $c = new Container();
    expect($c->has('missing'))->toBeFalse();
    $c->instance('present', new stdClass());
    expect($c->has('present'))->toBeTrue();
});

test('get() on an unresolvable, unbound id throws a PSR NotFound', function () {
    $c = new Container();
    // Illuminate 9 throws EntryNotFoundException (implements NotFoundExceptionInterface).
    // Pest's toThrow() uses class_exists(), which returns false for interfaces, so we
    // assert via try/catch to guarantee the PSR-11 contract is upheld.
    $thrown = null;
    try {
        $c->get('definitely-not-bound-xyz');
    } catch (\Throwable $e) {
        $thrown = $e;
    }
    expect($thrown)->toBeInstanceOf(NotFoundExceptionInterface::class);
});
