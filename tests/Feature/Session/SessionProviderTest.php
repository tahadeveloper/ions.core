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

// Regression: the legacy static Kernel::session() and the bound SessionManager
// must be the SAME underlying Session object once the container is booted.
// When they were two independent native Session instances, each called
// session_start(): structureBone() started one at boot, then
// StartSessionMiddleware's SessionManager start() threw "already started by
// PHP" on every web page under a real (non-cli) SAPI (php -S, fpm). The test
// suite never caught it because under the cli SAPI structureBone() uses an
// in-memory mock; this asserts the single-source-of-truth invariant directly.
test('Kernel::session() returns the bound SessionManager session (one session, not two)', function () {
    $managerSession = Kernel::app()->get('session')->getSession();

    expect(Kernel::session())->toBe($managerSession)
        ->and(Kernel::session())->toBeInstanceOf(\Ions\Support\Session::class);
});
