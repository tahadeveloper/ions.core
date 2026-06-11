<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Ions\Http\Middleware\CacheResponseMiddleware;
use Ions\Http\ResponseCache;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    bootFixtureKernel();
    putenv('APP_DEBUG');
    unset($_ENV['APP_DEBUG']);
});

function cacheMiddleware(): array
{
    $rc = new ResponseCache(new Repository(new ArrayStore()));

    return [new CacheResponseMiddleware($rc), $rc];
}

test('miss then hit: second request is served from cache with HIT header', function () {
    [$mw] = cacheMiddleware();
    $req = Request::create('/page');

    $calls = 0;
    $next = function (Request $r) use (&$calls) {
        $calls++;
        return new Response('body-' . $calls, 200);
    };

    $first = $mw->handle($req, $next);
    expect($first->headers->get('X-Ions-Cache'))->toBe('MISS')
        ->and($first->getContent())->toBe('body-1');

    $second = $mw->handle(Request::create('/page'), $next);
    expect($second->headers->get('X-Ions-Cache'))->toBe('HIT')
        ->and($second->getContent())->toBe('body-1') // not regenerated
        ->and($calls)->toBe(1);
});

test('conditional request on a cached ETag returns 304', function () {
    [$mw] = cacheMiddleware();
    $next = fn (Request $r) => new Response('etagged', 200);

    $first = $mw->handle(Request::create('/page'), $next);
    $etag = $first->getEtag();
    expect($etag)->not->toBeNull();

    $conditional = Request::create('/page', 'GET', server: ['HTTP_IF_NONE_MATCH' => $etag]);
    $second = $mw->handle($conditional, $next);

    expect($second->getStatusCode())->toBe(304)
        ->and($second->getContent())->toBe('')
        ->and($second->headers->get('X-Ions-Cache'))->toBe('HIT');
});

test('POST is never cached (bypass)', function () {
    [$mw, $rc] = cacheMiddleware();
    $next = fn (Request $r) => new Response('posted', 200);
    $res = $mw->handle(Request::create('/page', 'POST'), $next);
    expect($res->headers->get('X-Ions-Cache'))->toBeNull();
});

test('debug mode bypasses the cache entirely', function () {
    putenv('APP_DEBUG=true');
    $_ENV['APP_DEBUG'] = 'true';

    try {
        [$mw] = cacheMiddleware();
        $calls = 0;
        $next = function (Request $r) use (&$calls) {
            $calls++;
            return new Response('live-' . $calls, 200);
        };

        $first = $mw->handle(Request::create('/page'), $next);
        $second = $mw->handle(Request::create('/page'), $next);

        expect($first->headers->get('X-Ions-Cache'))->toBeNull()
            ->and($second->headers->get('X-Ions-Cache'))->toBeNull()
            ->and($calls)->toBe(2); // never served from cache
    } finally {
        putenv('APP_DEBUG');
        unset($_ENV['APP_DEBUG']);
    }
});

test('authenticated request is never cached', function () {
    [$mw] = cacheMiddleware();
    $next = fn (Request $r) => new Response('secret', 200);

    $req = Request::create('/page');
    $req->attributes->set('auth_user', (object) ['id' => 7]);

    $res = $mw->handle($req, $next);
    expect($res->headers->get('X-Ions-Cache'))->toBeNull();

    // A subsequent anonymous request must NOT see the authed body.
    $anon = $mw->handle(Request::create('/page'), $next);
    expect($anon->headers->get('X-Ions-Cache'))->toBe('MISS');
});

test('a Vary response is cached per varied-header value (no variant collision)', function () {
    [$mw] = cacheMiddleware();

    $calls = 0;
    $next = function (Request $r) use (&$calls) {
        $calls++;
        $accept = $r->headers->get('Accept', '');
        $res = new Response('variant-for-' . $accept . '-' . $calls, 200);
        $res->setVary(['Accept']);
        return $res;
    };

    $json1 = $mw->handle(Request::create('/v', 'GET', server: ['HTTP_ACCEPT' => 'application/json']), $next);
    expect($json1->headers->get('X-Ions-Cache'))->toBe('MISS');

    // Same URL, DIFFERENT Accept → must be a fresh MISS, not the JSON variant.
    $html1 = $mw->handle(Request::create('/v', 'GET', server: ['HTTP_ACCEPT' => 'text/html']), $next);
    expect($html1->headers->get('X-Ions-Cache'))->toBe('MISS')
        ->and($html1->getContent())->not->toBe($json1->getContent());

    // Repeat the JSON request → HIT, original JSON body.
    $json2 = $mw->handle(Request::create('/v', 'GET', server: ['HTTP_ACCEPT' => 'application/json']), $next);
    expect($json2->headers->get('X-Ions-Cache'))->toBe('HIT')
        ->and($json2->getContent())->toBe($json1->getContent())
        ->and($calls)->toBe(2); // one render per variant, none repeated
});

test('a cache store failure falls through to the live response', function () {
    // A ResponseCache over a store whose get() throws must never break the request.
    $throwing = new class () extends \Illuminate\Cache\Repository {
        public function __construct()
        {
            parent::__construct(new ArrayStore());
        }

        public function get($key, $default = null): mixed
        {
            throw new \RuntimeException('store down');
        }
    };
    $mw = new CacheResponseMiddleware(new ResponseCache($throwing));
    $res = $mw->handle(Request::create('/page'), fn (Request $r) => new Response('live', 200));
    expect($res->getContent())->toBe('live')->and($res->getStatusCode())->toBe(200);
});
