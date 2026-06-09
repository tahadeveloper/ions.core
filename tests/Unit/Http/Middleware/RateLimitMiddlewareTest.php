<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Ions\Http\Middleware\RateLimitMiddleware;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

function arrayCache(): Repository
{
    return new Repository(new ArrayStore());
}

function okNext(): callable
{
    return fn (Request $r) => new Response('ok');
}

test('allows requests up to the limit then 429s', function () {
    $cache = arrayCache();
    $mw = new RateLimitMiddleware($cache, maxAttempts: 3, decaySeconds: 60);
    $req = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '1.2.3.4']);

    expect($mw->handle($req, okNext())->getStatusCode())->toBe(200); // 1
    expect($mw->handle($req, okNext())->getStatusCode())->toBe(200); // 2
    expect($mw->handle($req, okNext())->getStatusCode())->toBe(200); // 3
    $blocked = $mw->handle($req, okNext());                          // 4 → over limit
    expect($blocked->getStatusCode())->toBe(429)
        ->and($blocked->headers->get('Retry-After'))->not->toBeNull();
});

test('different clients (IPs) have independent buckets', function () {
    $cache = arrayCache();
    $mw = new RateLimitMiddleware($cache, maxAttempts: 1, decaySeconds: 60);
    $a = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '1.1.1.1']);
    $b = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '2.2.2.2']);
    expect($mw->handle($a, okNext())->getStatusCode())->toBe(200);
    expect($mw->handle($a, okNext())->getStatusCode())->toBe(429); // a over limit
    expect($mw->handle($b, okNext())->getStatusCode())->toBe(200); // b independent
});

test('different routes have independent buckets', function () {
    $cache = arrayCache();
    $mw = new RateLimitMiddleware($cache, maxAttempts: 1, decaySeconds: 60);
    $login = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '1.1.1.1']);
    $reset = Request::create('/reset', 'POST', server: ['REMOTE_ADDR' => '1.1.1.1']);
    expect($mw->handle($login, okNext())->getStatusCode())->toBe(200);
    expect($mw->handle($login, okNext())->getStatusCode())->toBe(429);
    expect($mw->handle($reset, okNext())->getStatusCode())->toBe(200); // different route
});
