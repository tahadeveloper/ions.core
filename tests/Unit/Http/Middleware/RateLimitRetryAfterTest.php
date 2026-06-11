<?php

/**
 * Regression: the 429 Retry-After must reflect the REMAINING window, not the
 * static configured decay. The window starts on the first hit; a request that
 * 429s several seconds later must advertise a smaller Retry-After.
 */

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Ions\Http\Middleware\RateLimitMiddleware;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

function rl_cache(): Repository
{
    return new Repository(new ArrayStore());
}

function rl_next(): callable
{
    return fn (Request $r) => new Response('ok');
}

test('429 Retry-After decays as the window elapses (companion :reset key)', function () {
    $cache = rl_cache();
    $decay = 60;
    $mw = new RateLimitMiddleware($cache, maxAttempts: 2, decaySeconds: $decay);
    $req = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '9.9.9.9']);

    // Burn the allowance so the next call 429s.
    $mw->handle($req, rl_next());
    $mw->handle($req, rl_next());

    // Simulate ~50s of elapsed time by rewinding the companion reset marker so
    // only ~10s remain in the window.
    $key = 'ratelimit:' . sha1('9.9.9.9' . '|' . '/login');
    $remaining = 10;
    $cache->put($key . ':reset', time() + $remaining, $decay);

    $blocked = $mw->handle($req, rl_next());

    expect($blocked->getStatusCode())->toBe(429);

    $retryHeader = (int) $blocked->headers->get('Retry-After');
    $body = json_decode((string) $blocked->getContent(), true);
    $retryBody = (int) ($body['retry_after'] ?? -1);

    // Reports the remaining window, strictly less than the static decay.
    expect($retryHeader)->toBeLessThan($decay)
        ->and($retryHeader)->toBeGreaterThan(0)
        ->and($retryHeader)->toBeLessThanOrEqual($remaining)
        ->and($retryBody)->toBe($retryHeader);
});

test('429 Retry-After never drops below 1 even when the window has elapsed', function () {
    $cache = rl_cache();
    $decay = 30;
    $mw = new RateLimitMiddleware($cache, maxAttempts: 1, decaySeconds: $decay);
    $req = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '8.8.8.8']);

    $mw->handle($req, rl_next()); // first hit allowed, window opens

    // Force the reset marker fully into the past.
    $key = 'ratelimit:' . sha1('8.8.8.8' . '|' . '/login');
    $cache->put($key . ':reset', time() - 5, $decay);

    $blocked = $mw->handle($req, rl_next());

    expect($blocked->getStatusCode())->toBe(429)
        ->and((int) $blocked->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1);
});
