<?php

use Illuminate\Cache\Repository;
use Ions\commands\CacheClearResponsesCommand;
use Ions\Foundation\Kernel;
use Ions\Http\ResponseCache;
use Ions\Security\CacheRevocationStore;
use Symfony\Component\HttpFoundation\Response;

beforeEach(fn () => bootFixtureKernel());

/**
 * A response cache backed by a bare (non-tag) store, mirroring the file/database
 * drivers used in production where cache.default and cache.persistent_store
 * share ONE store — so a full store->clear() would wipe JWT revocations and
 * rate-limit/forgot throttles alongside the response cache.
 */
function nonTagResponseCache(): array
{
    $bare = new class () implements \Illuminate\Contracts\Cache\Store {
        /** @var array<string, mixed> */
        public array $data = [];

        public function get($key): mixed
        {
            return $this->data[$key] ?? null;
        }

        public function many(array $keys): array
        {
            return array_map(fn ($k) => $this->get($k), array_combine($keys, $keys));
        }

        public function put($key, $value, $seconds): bool
        {
            $this->data[$key] = $value;
            return true;
        }

        public function putMany(array $values, $seconds): bool
        {
            foreach ($values as $k => $v) {
                $this->data[$k] = $v;
            }
            return true;
        }

        public function increment($key, $value = 1): int|bool
        {
            return false;
        }

        public function decrement($key, $value = 1): int|bool
        {
            return false;
        }

        public function forever($key, $value): bool
        {
            return $this->put($key, $value, 0);
        }

        public function forget($key): bool
        {
            unset($this->data[$key]);
            return true;
        }

        public function flush(): bool
        {
            $this->data = [];
            return true;
        }

        public function getPrefix(): string
        {
            return '';
        }
    };

    $repository = new Repository($bare);
    Kernel::app()->bind(ResponseCache::class, fn () => new ResponseCache($repository));

    return [new ResponseCache($repository), $repository];
}

test('cache:clear-responses empties stored response-cache entries (tag store)', function () {
    /** @var ResponseCache $cache */
    $cache = Kernel::app()->make(ResponseCache::class);
    $cache->put('response_cache:abc', new Response('cached', 200), 300);
    expect($cache->get('response_cache:abc'))->not->toBeNull();

    $tester = runConsoleCommand(new CacheClearResponsesCommand());

    expect($tester->getStatusCode())->toBe(0)
        ->and($cache->get('response_cache:abc'))->toBeNull()
        ->and($tester->getDisplay())->toContain('Response cache cleared');
});

test('cache:clear-responses on a NON-tag store does NOT wipe a revocation entry without --force', function () {
    // IMPORTANT-1: with file defaults cache.default and cache.persistent_store
    // are the SAME store, so a full clear() un-revokes logged-out JWTs and
    // resets throttles. A routine response-cache purge must NEVER do that.
    [, $repository] = nonTagResponseCache();
    /** @var ResponseCache $cache */
    $cache = Kernel::app()->make(ResponseCache::class);

    // Security state living in the SAME store.
    $revocations = new CacheRevocationStore($repository);
    $revocations->revoke('jti-logged-out', 3600);
    $cache->put('response_cache:page', new Response('cached', 200), 300);

    $tester = runConsoleCommand(new CacheClearResponsesCommand());

    expect($tester->getStatusCode())->toBe(0)
        // The revocation (and any throttle counter) MUST survive.
        ->and($revocations->isRevoked('jti-logged-out'))->toBeTrue()
        // The destructive clear was refused; nothing was purged.
        ->and($cache->get('response_cache:page'))->not->toBeNull()
        // …and the operator is told why, naming what --force would also wipe.
        ->and($tester->getDisplay())->toContain('--force')
        ->and($tester->getDisplay())->toContain('revocation');
});

test('cache:clear-responses --force on a NON-tag store flushes the whole store and warns loudly', function () {
    [, $repository] = nonTagResponseCache();
    /** @var ResponseCache $cache */
    $cache = Kernel::app()->make(ResponseCache::class);

    $revocations = new CacheRevocationStore($repository);
    $revocations->revoke('jti-logged-out', 3600);
    $cache->put('response_cache:page', new Response('cached', 200), 300);

    $tester = runConsoleCommand(new CacheClearResponsesCommand(), ['--force' => true]);

    expect($tester->getStatusCode())->toBe(0)
        // Explicitly forced: the whole shared store is wiped (revocation gone).
        ->and($revocations->isRevoked('jti-logged-out'))->toBeFalse()
        ->and($cache->get('response_cache:page'))->toBeNull()
        ->and($tester->getDisplay())->toContain('revocation');
});
