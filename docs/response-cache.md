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
  *state*. The web stack starts a session on every request, so an empty,
  just-started session does **not** count as stateful; a session is stateful
  when it holds attribute data **or a non-empty flash bag** (flash messages /
  `errors()` / `old()` — these live in the FlashBag, separate from the attribute
  bag, and are checked non-destructively);
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

### Framework-rendered pages

A **form-less** Twig page rendered through the framework view layer (`view()` /
`render()` / `BaseController::view()`) **is cacheable**. The `_csrf_token` Twig
global is lazy (`Ions\View\CsrfTokenProxy`): it only generates and writes the
session token when a template actually outputs `{{ _csrf_token }}`. A page that
never references it leaves the session empty, so it passes the "no session
state" gate above.

> Earlier versions seeded `_csrf_token` eagerly on every render, which wrote the
> token into the session unconditionally and made **every** framework-rendered
> page stateful — so none could be response-cached. That is fixed.

A page that **renders the CSRF token** — either directly via `{{ _csrf_token }}`
or through a form helper (`{{ ionToken('web') }}`) — writes the per-session token
and is therefore **not** cached. This is the correct safety property: such a page
embeds per-session state that must never be shared across users.

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

> **Caution — `Vary` largely defeats the cache.** Every distinct value of a
> varied header produces a *separate* cache entry (per-variant fragmentation). A
> response that varies on `Cookie` or `Authorization` therefore caches almost
> nothing useful — those headers are near-unique per client, so each request
> gets its own entry and the hit rate collapses. (Such responses are usually
> uncacheable anyway: a `Cookie`/`Authorization` request typically carries a
> session or auth user, which `shouldCache()` already rejects.) Reserve `Vary`
> for low-cardinality dimensions like `Accept` or `Accept-Encoding`.

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
  key-prefix scan), the only way to purge it is a **full store flush** — but
  with the skeleton defaults `cache.default` and `cache.persistent_store` point
  at the **same store**, which also holds the **JWT revocation list** and the
  **rate-limit / forgot-password throttles**. A blind full flush would therefore
  un-revoke logged-out tokens and reset throttles. The command **refuses** that
  destructive flush by default — it does nothing and tells you why, naming what
  would be wiped:

  ```bash
  ions cache:clear-responses           # non-tag store → NO-OP, prints guidance
  ions cache:clear-responses --force   # explicitly accept the blast radius
  ```

  `--force` performs the full store flush and warns loudly that revocations and
  throttles went with it. The clean fix is to point `cache.default` (or a
  dedicated response store) at **redis/memcached** so the tag purge is targeted
  and `--force` is never needed.

## Safety guarantees

- **Never caches authenticated/session responses.** Detection is dual-sided: the
  request side (`auth_user` attribute, a session carrying attribute data, **or a
  non-empty flash bag** — `errors()`/`old()`/`flash()` write to the FlashBag,
  which `Session::all()` does *not* cover, so it is peeked separately and
  non-destructively) and the response side (any `Set-Cookie`). Stored entries
  have `Set-Cookie` stripped.
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
