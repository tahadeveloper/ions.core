<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Ions\Http\ResponseCache;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    bootFixtureKernel();
});

function responseCache(): ResponseCache
{
    return new ResponseCache(new Repository(new ArrayStore()));
}

// ---------------------------------------------------------------------------
// shouldCache() matrix — conservative: only anonymous GET 200s with no
// per-user state are cacheable.
// ---------------------------------------------------------------------------

test('shouldCache: GET 200 with no session is cacheable', function () {
    $cache = responseCache();
    $req = Request::create('/page');
    $res = new Response('hi', 200);
    expect($cache->shouldCache($req, $res))->toBeTrue();
});

test('shouldCache: POST is not cacheable', function () {
    $cache = responseCache();
    $req = Request::create('/page', 'POST');
    expect($cache->shouldCache($req, new Response('hi', 200)))->toBeFalse();
});

test('shouldCache: non-200 is not cacheable', function () {
    $cache = responseCache();
    $req = Request::create('/page');
    expect($cache->shouldCache($req, new Response('err', 500)))->toBeFalse();
    expect($cache->shouldCache($req, new Response('nf', 404)))->toBeFalse();
    expect($cache->shouldCache($req, new Response('moved', 302)))->toBeFalse();
});

test('shouldCache: a session carrying data makes it uncacheable', function () {
    $cache = responseCache();
    $req = Request::create('/page');
    $session = new \Symfony\Component\HttpFoundation\Session\Session(
        new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
    );
    $session->start();
    $session->set('flash', 'saved!'); // session now holds per-user data
    $req->setSession($session);
    expect($cache->shouldCache($req, new Response('hi', 200)))->toBeFalse();
});

test('shouldCache: an empty just-started session is still cacheable (anonymous)', function () {
    // The web stack starts a session on every request; an empty one is anonymous.
    $cache = responseCache();
    $req = Request::create('/page');
    $session = new \Symfony\Component\HttpFoundation\Session\Session(
        new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
    );
    $session->start();
    $req->setSession($session);
    expect($cache->shouldCache($req, new Response('hi', 200)))->toBeTrue();
});

test('shouldCache: an auth_user attribute makes it uncacheable', function () {
    $cache = responseCache();
    $req = Request::create('/page');
    $req->attributes->set('auth_user', (object) ['id' => 1]);
    expect($cache->shouldCache($req, new Response('hi', 200)))->toBeFalse();
});

test('shouldCache: a Set-Cookie on the response makes it uncacheable', function () {
    $cache = responseCache();
    $req = Request::create('/page');
    $res = new Response('hi', 200);
    $res->headers->setCookie(\Symfony\Component\HttpFoundation\Cookie::create('sid', 'abc'));
    expect($cache->shouldCache($req, $res))->toBeFalse();
});

test('shouldCache: explicitly private or no-store responses are uncacheable', function () {
    $cache = responseCache();
    $req = Request::create('/page');

    $priv = new Response('hi', 200);
    $priv->setPrivate();
    expect($cache->shouldCache($req, $priv))->toBeFalse();

    $noStore = new Response('hi', 200);
    $noStore->headers->addCacheControlDirective('no-store');
    expect($cache->shouldCache($req, $noStore))->toBeFalse();

    // The bare conservative default (no explicit directives) is NOT an opt-out.
    $fresh = new Response('hi', 200);
    expect($cache->shouldCache($req, $fresh))->toBeTrue();
});

// ---------------------------------------------------------------------------
// key() — query order independent, Vary aware.
// ---------------------------------------------------------------------------

test('key includes query string regardless of param order', function () {
    $cache = responseCache();
    $a = $cache->key(Request::create('/page?b=2&a=1'));
    $b = $cache->key(Request::create('/page?a=1&b=2'));
    $c = $cache->key(Request::create('/page?a=1'));
    expect($a)->toBe($b)->and($a)->not->toBe($c);
});

test('key incorporates Vary header request values', function () {
    $cache = responseCache();
    $json = Request::create('/page', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    $html = Request::create('/page', 'GET', server: ['HTTP_ACCEPT' => 'text/html']);
    expect($cache->key($json, ['Accept']))->not->toBe($cache->key($html, ['Accept']));
});

// ---------------------------------------------------------------------------
// put()/get() round-trip — body + status + safe headers; strips Set-Cookie.
// ---------------------------------------------------------------------------

test('put then get round-trips status, body and headers but strips Set-Cookie', function () {
    $cache = responseCache();
    $key = 'k1';
    $res = new Response('payload', 200);
    $res->headers->set('Content-Type', 'text/html');
    $res->headers->setCookie(\Symfony\Component\HttpFoundation\Cookie::create('sid', 'secret'));

    $cache->put($key, $res, 300);
    $hit = $cache->get($key);

    expect($hit)->not->toBeNull()
        ->and($hit->getStatusCode())->toBe(200)
        ->and($hit->getContent())->toBe('payload')
        ->and($hit->headers->get('Content-Type'))->toBe('text/html')
        ->and($hit->headers->get('Set-Cookie'))->toBeNull();
});

test('get returns null on a miss', function () {
    expect(responseCache()->get('absent'))->toBeNull();
});

test('flush returns true (tag purge) on a tag-capable store and empties entries', function () {
    // ArrayStore supports tags, so the response cache uses a targeted tag flush.
    $cache = responseCache();
    $cache->put('k', new Response('x', 200), 300);
    expect($cache->get('k'))->not->toBeNull()
        ->and($cache->flush())->toBeTrue()
        ->and($cache->get('k'))->toBeNull();
});

test('flush returns false (full-store fallback) on a non-tag store', function () {
    // A bare Store with NO tags() method → documented full-store flush fallback.
    $bare = new class () implements \Illuminate\Contracts\Cache\Store {
        /** @var array<string, mixed> */
        private array $data = [];

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
    $store = new Repository($bare);
    expect($store->supportsTags())->toBeFalse();
    $cache = new ResponseCache($store);
    $cache->put('k', new Response('x', 200), 300);
    expect($cache->get('k'))->not->toBeNull()
        ->and($cache->flush())->toBeFalse()
        ->and($cache->get('k'))->toBeNull();
});
