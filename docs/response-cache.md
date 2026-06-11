# Response cache (12.5)

A full-page cache for anonymous, cacheable `GET` responses. It is **opt-in per
route or group**, conservative by default (it never caches anything that could
leak one user's data to another), and it **never breaks a response** — every
cache interaction is wrapped so a failure degrades to serving live.

It also provides conditional-request (ETag / `304 Not Modified`) handling, which
works even for the first, uncached hit.

- `Ions\Http\ResponseCache` — the pure cache logic (cacheability rules, key
  derivation, store/rehydrate, invalidation). Unit-testable without HTTP.
- `Ions\Http\Middleware\CacheResponseMiddleware` — the `cache.response`
  middleware that drives it inside the request pipeline.

## Enabling it

The middleware is wired to the `cache.response` alias in
`config/app.php`:

```php
'middleware_aliases' => [
    // …
    'cache.response' => \Ions\Http\Middleware\CacheResponseMiddleware::class,
],
```

Attach it to the routes you want cached — it is **never** in a default stack:

```php
// A single route
Route::get('/pricing', 'PageController@pricing')->middleware(['cache.response']);

// A whole group
Route::prefix('docs')->middleware(['cache.response'])->group(function () {
    Route::get('/', 'DocsController@index');
    Route::get('/{slug}', 'DocsController@show');
});
```

## What is cacheable

A response is stored only when **all** of these hold (see
`ResponseCache::shouldCache()`):

- the request is a `GET`;
- the response status is exactly `200`;
- the request is **anonymous** — no resolved auth user (`auth_user` /
  `auth_user_id` request attributes from `AuthMiddleware`) and no session
  *data*. The web stack starts a session on every request, so an empty,
  just-started session does **not** count as stateful; only a session that
  actually holds data does;
- the response carries no `Set-Cookie` (a cookie means per-client state);
- the response is not explicitly marked `private` or `no-store`.

> Note on `no-cache`: Symfony emits a conservative `no-cache, private`
> `Cache-Control` for any response that set no caching directives of its own,
> which is indistinguishable from an explicit `no-cache`. We therefore do **not**
> treat `no-cache` as an opt-out — and a full-page cache backed by ETag/304
> revalidation is compatible with its "revalidate before reuse" intent anyway.
> Use `private`, `no-store`, or a `max-age=0` to opt a route out.

Per-user state is stripped before storing: `Set-Cookie` and hop-by-hop headers
(`Connection`, `Transfer-Encoding`, …) are never written to the cache.

## HIT / MISS

On a cache **MISS** the response is generated, stored, and stamped
`X-Ions-Cache: MISS`. On a **HIT** the stored response is rehydrated (status,
safe headers, body) and stamped `X-Ions-Cache: HIT` — the controller does not
run.

## ETag / `304 Not Modified`

When a cacheable response has no validator, the middleware attaches an `ETag`
hashed from the body so future conditional requests can be answered cheaply.

If a request's `If-None-Match` (or `If-Modified-Since`) matches the response's
validator, the middleware returns a `304 Not Modified` with an empty body. This
happens for both a cached HIT and a fresh first hit, so the body transfer is
saved either way.

## Vary

If a cached response declared a `Vary` header (e.g. `Vary: Accept`), the cache
key folds in the request values of those headers, so different representations
of the same URL never collide. The Vary dimension is recorded at the base key on
the first MISS and consulted on subsequent lookups.

The cache key is otherwise `method + scheme + host + path + sorted query` — query
parameter order does not matter.

## TTL

TTL is the primary expiry mechanism. Configure it in `config/cache.php`:

```php
'response' => [
    'enabled' => true,      // master switch (also bypassed on APP_DEBUG)
    'ttl' => 300,           // store TTL (seconds) when the response sets none
    'max_ttl' => 86400,     // hard ceiling on any requested TTL
    'prefix' => 'response_cache:',
    'tag' => 'ions_response_cache',
],
```

A response's own positive `Cache-Control: max-age` is used as the TTL when set;
otherwise the configured `ttl` applies. Either way the value is clamped to
`max_ttl`.

## Invalidation — `cache:clear-responses`

```bash
ions cache:clear-responses
```

- On a **tag-capable** store (redis, memcached, and the in-memory array store)
  only the response-cache tag is purged — every other cache entry survives.
- On a store with **no tag support** (the `file` / `database` drivers expose no
  key-prefix scan), the command falls back to flushing the **whole default
  store** and warns that it did so. Point `cache.default` (or a dedicated store)
  at redis/memcached if you need targeted response-cache invalidation without
  touching sibling cache data.

## Safety guarantees

- **Never caches authenticated/session responses.** Detection is dual-sided: the
  request side (`auth_user` attribute or a session carrying data) and the
  response side (any `Set-Cookie`). Stored entries have `Set-Cookie` stripped.
- **Debug bypass.** When `APP_DEBUG` is truthy the middleware short-circuits to
  the live response on every request — nothing is read from or written to the
  cache.
- **Non-GET bypass.** Any non-`GET` verb passes straight through.
- **Never breaks a response.** Every store interaction is wrapped in a
  `try/catch`; a cache failure can only ever fall through to serving live.

## Benchmark

`bench/bench.php` measures a moderately expensive render (~50 KB body) served
live vs. served from the response cache (php 8.3, in-process, array store,
N=200):

| | per request |
| --- | --- |
| cache off (live render every time) | ~0.46–0.52 ms |
| cache on (HIT, no render) | ~0.04–0.05 ms |

≈ **10–12× faster** per cached request. The array store has no I/O; a persistent
store (file/redis) adds store latency but still skips the render and any
database work behind it.
