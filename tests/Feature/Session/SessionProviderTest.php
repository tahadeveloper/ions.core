<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\Session\SessionManager;

beforeEach(fn () => bootFixtureKernel());

test('the session manager is bound in the container', function () {
    expect(Kernel::app()->has('session'))->toBeTrue()
        ->and(Kernel::app()->get('session'))->toBeInstanceOf(SessionManager::class);
});

test('the session binding is a singleton', function () {
    expect(Kernel::app()->get('session'))->toBe(Kernel::app()->get('session'));
});

test('session() with no args returns the manager', function () {
    expect(session())->toBeInstanceOf(SessionManager::class);
});

test('session() with an array puts values; session() with a string gets them', function () {
    session()->start();
    session(['foo' => 'bar']);
    expect(session('foo'))->toBe('bar')
        ->and(session('missing', 'def'))->toBe('def');
});
