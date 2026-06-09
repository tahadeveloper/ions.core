<?php

declare(strict_types=1);

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;
use Ions\Foundation\Kernel;

beforeEach(fn () => bootFixtureKernel());

test('the cache manager is bound in the container', function () {
    expect(Kernel::app()->has('cache'))->toBeTrue()
        ->and(Kernel::app()->get('cache'))->toBeInstanceOf(CacheManager::class);
});

test('the cache binding is a singleton', function () {
    expect(Kernel::app()->get('cache'))->toBe(Kernel::app()->get('cache'));
});

test('cache() with no args returns the default repository', function () {
    expect(cache())->toBeInstanceOf(Repository::class);
});

test('cache() puts, gets and forgets over the array store', function () {
    cache(['foo' => 'bar'], 60);
    expect(cache('foo'))->toBe('bar')
        ->and(cache('missing', 'def'))->toBe('def');

    cache()->forget('foo');
    expect(cache('foo'))->toBeNull();
});

test('the manager resolves a named store', function () {
    $store = Kernel::app()->get('cache')->store('array');
    expect($store)->toBeInstanceOf(Repository::class);
    $store->put('k', 'v', 60);
    expect($store->get('k'))->toBe('v');
});

test('the revocation store is backed by the shared cache and still works', function () {
    /** @var \Ions\Security\RevocationStore $store */
    $store = Kernel::app()->get('revocation_store');
    expect($store)->toBeInstanceOf(\Ions\Security\CacheRevocationStore::class);

    expect($store->isRevoked('abc'))->toBeFalse();
    $store->revoke('abc', 60);
    expect($store->isRevoked('abc'))->toBeTrue();
});

test('the rate limiter resolves with the shared cache and throttles', function () {
    config(['app.ratelimit.max' => 1, 'app.ratelimit.decay' => 60]);

    /** @var \Ions\Http\Middleware\RateLimitMiddleware $mw */
    $mw = Kernel::app()->make(\Ions\Http\Middleware\RateLimitMiddleware::class);

    $req = \Ions\Support\Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '9.9.9.9']);
    $next = fn ($r) => new \Symfony\Component\HttpFoundation\Response('ok');

    expect($mw->handle($req, $next)->getStatusCode())->toBe(200)
        ->and($mw->handle($req, $next)->getStatusCode())->toBe(429);
});
