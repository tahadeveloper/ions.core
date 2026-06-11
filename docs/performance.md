# Performance

How the framework avoids per-request rebuild costs, and the production caches
you should build on deploy. All numbers below were measured with
`bench/bench.php` (php 8.3, in-process kernel, N=200 requests / N=50 boots) on
the test fixtures — absolute values are machine-dependent; the ratios are the
point.

## What happens without caches

Per **process** (since 4.1; previously per *request*):

- `routes/{web|api}.php` (or `.yaml`) is required/parsed and the `Http/`
  (web) / `Http/Api` (api) directories are reflection-scanned for attribute
  routes — **once per group per process**, memoized by the kernel.
- Every `config/*.php` file is included at boot.
- **Provider discovery** (when `app.providers` is not set and discovery is
  on): boot parses `vendor/composer/installed.json` for
  `extra.ions.providers`, globs the host `{app|src}/Providers/` directory and
  regex-reads each file for its class name — a few milliseconds per boot,
  which in classic FPM means per request. `ions discover:cache` freezes the
  merged list into `var/cache/providers.php`; boot then loads it with one
  `require` and skips every scan. Setting an explicit `app.providers` list
  remains the zero-scan alternative that needs no cache at all.
- One shared Twig `Environment` is built lazily per process (`view.env`
  singleton); renders reuse it.

In classic PHP-FPM every request is a fresh process, so "once per process"
still means once per request — that first-hit cost is what the compiled caches
remove.

## Commands

| Command | Effect |
| --- | --- |
| `ions route:cache` | Compiles both route groups into `var/cache/routes/{web\|api}.php` (Symfony `CompiledUrlMatcherDumper`). |
| `ions route:clear` | Removes the compiled route files. |
| `ions config:cache` | Merges every `config/*.php` into one `var/cache/config.php`. |
| `ions config:clear` | Removes the cached config file. |
| `ions discover:cache` | Freezes the discovered provider list into `var/cache/providers.php` (skips the installed.json/glob/regex scans at boot). |
| `ions discover:clear` | Removes the cached provider list (boot discovers live again). |
| `ions optimize` | `route:cache` + `config:cache` + `discover:cache` in one shot (deploy hook). |
| `ions optimize:clear` | `route:clear` + `config:clear` + `discover:clear` + deletes the compiled Twig cache (`var/cache/twig`). |
| `ions preload:generate` | Writes `var/cache/preload.php`, an `opcache.preload` file compiling the framework hot path. |

### When the caches apply

- **Never in debug.** When `APP_DEBUG` is truthy the kernel always reads live
  routes, config and provider discovery — you cannot serve a stale cache
  while developing.
- Otherwise the kernel uses a cache **iff its file exists**: matching goes
  through `CompiledUrlMatcher` (no route file parse, no attribute scan), boot
  loads the single merged config array with one `require`, and the provider
  list comes from `var/cache/providers.php` with one `require` (no
  installed.json parse, no `Providers/` glob).
- Commands that need the real `RouteCollection` (`route:list`,
  `openapi:generate`) always build live — only request *matching* uses the
  compiled cache.

### Invalidation

The caches are plain files — they never invalidate themselves. Re-run
`ions optimize` (or `route:cache` / `config:cache` / `discover:cache`) after
**every deploy** and after any change to routes, controllers with route
attributes, `config/`, installed composer packages (`composer install`/
`update`/`require`) or the host `Providers/` directory. A cached provider
FQCN that no longer exists is filtered out at boot with a logged warning
(`var/logs/app.log`), never a fatal. `optimize:clear` returns you to
fully-live behavior. In-process memoized state
(per-group collections, loaded compiled arrays, the shared Twig env) is
rebuilt whenever the kernel re-boots.

### Constraints

- **Closure routes cannot be cached.** `route:cache` fails naming the route;
  point the route at `Controller::method` instead.
- **Closure config values cannot be cached.** `config:cache` fails naming the
  offending dot-path key.
- `env()` calls inside config files are evaluated at `config:cache` build
  time — changing the environment requires re-running `config:cache`.
- Per-route middleware names are embedded into the compiled match as a
  `_middleware` default, so route middleware behaves identically cached or
  live.

## Query log

`DatabaseProvider` enables the connection query log only when
`config('database.query_log')` is `true` (default `false`). `APP_DEBUG` alone
no longer enables it — the log grows unboundedly in memory. See
[config.md](config.md#databasequery_log) and UPGRADE-4.1.

## N+1 query detector (debug-only)

When `APP_DEBUG` is truthy **and** `database.query_log` is on,
`DatabaseProvider::boot()` automatically attaches
`Ions\Database\Listeners\DetectNPlusOne` to the kernel's `RequestHandled`
event. At the end of each request it runs `Ions\Database\NPlusOneDetector`
over the bounded query log and writes **one warning per offending pattern** to
`var/logs/performance.log`:

```
ions.WARNING: Possible N+1 query: pattern executed 26 times (12.40 ms total) during /orders — fix with eager loading (->with(...)) or one WHERE ... IN (...) query. Pattern: select * from products where id = ? {"pattern":"select * from products where id = ?","count":26,"total_time_ms":12.4,"path":"/orders"}
```

### How it works (and the honest limitation)

Each `SELECT` in the query log is normalized into a shape-pattern — lowercased,
whitespace collapsed, string/numeric literals replaced with `?`,
`IN (?, ?, …)` lists collapsed to `in (...)` — and any **WHERE-carrying
pattern repeated >= threshold times** (default 5) within one request is
flagged.

This is a **log-based heuristic**, not ORM-level lazy-load detection. The
query log cannot show *where* a query came from — only that the same
single-row-shaped `SELECT` ran many times in one request, which is exactly the
N+1 signature. Expect occasional false positives from intentional query loops;
fix real offenders with eager loading (`->with('relation')`) or one
`WHERE … IN (...)` query.

Since 4.3 the heuristic is **complemented** by ORM strict mode (below), which
hooks relation loading itself and throws at the exact offending line. Use
both: strict mode catches lazy loads deterministically in dev, the heuristic
also catches N+1 shapes that never go through Eloquent relations (manual
loops, raw queries).

### Config keys

| Key | Default | Effect |
| --- | --- | --- |
| `database.query_log` | `false` | Prerequisite — without the query log there is nothing to analyze. |
| `database.nplusone.enabled` | `true` | Escape hatch: `false` prevents the listener from ever attaching. |
| `database.nplusone.threshold` | `5` | Repetitions of one pattern that trigger the warning. |

Production is untouched: with `APP_DEBUG` off (or `query_log` off, or
`enabled => false`) **no listener is attached at all** — zero hot-path cost.
The listener itself never throws; diagnostics failures are swallowed so they
cannot break a response.

## ORM strict mode (debug-only, 4.3)

With `APP_DEBUG` on (and unless `config('database.strict') => false`),
`DatabaseProvider::boot()` enables Eloquent's own development guards:

- **`Model::preventLazyLoading(true)`** — accessing an unloaded relation on a
  model hydrated in a multi-model result set throws
  `Illuminate\Database\LazyLoadingViolationException` naming the model and
  relation, at the exact line of the lazy access. (Upstream Eloquent
  deliberately exempts single `first()`/`find()` models — one lazy load is not
  an N+1.)
- **`Model::preventSilentlyDiscardingAttributes(true)`** — a `fill()` that
  `$fillable` would silently drop throws instead.

Production (`APP_DEBUG` off) is always relaxed regardless of config — the
statics are explicitly re-set on every boot, so worker re-boots self-correct.
Deterministic where the N+1 log heuristic above is probabilistic; the
heuristic still covers query loops that bypass relations. See
[config.md](config.md#databasestrict).

## Debug toolbar (debug-only)

When `APP_DEBUG` is truthy, `Ions\Http\Middleware\DebugToolbarMiddleware` is
appended to the **web** stack at stack-build time and injects a small
fixed-position footer bar (inline CSS/JS, dismissable) before `</body>` in
HTML responses:

| Segment | Source |
| --- | --- |
| request wall ms | middleware start → end (covers controller + inner middleware) |
| method + path (route name) | the matched request |
| `queries: N (X ms)` | the connection query log; `log off` unless `database.query_log` is on |
| peak memory MB | `memory_get_peak_usage(true)` |
| PHP + Ions versions | `PHP_VERSION` + composer runtime metadata |

Guarantees: production never constructs the middleware (zero cost); only
`text/html` bodies containing `</body>` are touched — JSON/api, redirects,
streamed and binary responses pass through byte-identical; the whole
injection is wrapped in a try/catch, so it can never break a response. Hide it
in debug with `config('app.debug_toolbar') => false`.

## opcache preload (optional)

`ions preload:generate` emits a curated `opcache_compile_file()` list (kernel,
container, providers, middleware, routing, request/response). Point php.ini at
it:

```ini
opcache.preload=/path/to/host/var/cache/preload.php
opcache.preload_user=www-data
```

Regenerate after upgrading `ionzile/core`.

## Response cache (12.5)

The compiled caches above shrink the *framework* per-request cost; the response
cache attacks the **application** cost — the controller, template render and any
database work behind a page. It is an opt-in, full-page cache for anonymous
cacheable `GET` 200s, attached per route/group via the `cache.response`
middleware alias. On a cache HIT the controller never runs; the stored
status/headers/body are rehydrated and served with `X-Ions-Cache: HIT`.

It is conservative by design (never caches authenticated/session responses or
anything carrying `Set-Cookie`/`private`/`no-store`), bypasses entirely on
`APP_DEBUG`, supports ETag/`304 Not Modified` even on the first hit, and never
breaks a response (every store interaction is guarded). TTL is the primary
expiry; `ions cache:clear-responses` purges entries (tag-scoped on
redis/memcached/array, whole-store fallback on file). Full reference:
[response-cache.md](response-cache.md).

`bench/bench.php` measures a moderately expensive render (~50 KB body) served
live vs. from the response cache (php83, in-process, array store, N=200):

| | per request |
| --- | --- |
| cache off (live render every time) | ~0.46–0.52 ms |
| cache on (HIT, no render) | ~0.04–0.05 ms |

≈ **10–12× faster** per cached request. The array store has no I/O; a persistent
store (file/redis) adds store latency but still skips the render and any
database work behind it.

## Measured impact (fixtures, php83)

| Step | Metric | Before | After |
| --- | --- | --- | --- |
| Route capture once per process | steady-state `handle()` /ping | 0.218 ms/req | 0.084 ms/req (~61% faster) |
| + compiled route cache | cold boot + first request | 1.29 ms | 0.99 ms (first-match cost ~0.79 → ~0.20 ms) |
| + config cache | boot | 0.73 ms | 0.33 ms (~54% faster) |
| Twig `view.env` singleton | no-override `ViewFactory::make()` | 0.060–0.069 ms | ~0.001 ms |

Numbers were measured during the Phase 8.1 optimization work; absolute values
are machine-dependent and boot has since gained weight from the 4.1 feature
providers (a fresh `bench/bench.php` run reports boot ≈ 4.4 ms on the same
fixture) — the per-step ratios are the point.

### Middleware stacks — evaluated and intentionally not cached

`Kernel::defaultMiddleware()` costs **0.0071 ms/request** (measured, N=2000)
and per-route alias resolution **0.0013 ms** — under 1% of any realistic
request. The stack is deliberately rebuilt per request because it must react
to runtime changes (`app.csrf.enabled` config, swapped `csrf`/`jwt`/
`user_provider` bindings); a correct cache key over those inputs costs nearly
as much as building the stack. Revisit only if profiling shows it matters for
your workload.

## Worker mode

A persistent worker runtime (one boot, many requests — FrankenPHP/RoadRunner
style) builds on these per-process caches. The mechanism shipped in Phase 8.2:
`Kernel::resetForRequest()` clears all per-request state (request/response
statics, the framework session + CSRF storage, the per-request Twig globals,
the query log) while keeping every per-process cache above intact (config,
container singletons, the route memo, the Twig Environment), and
`Ions\Runtime\WorkerRunner` drives the reset-then-handle loop. Worker mode is
stable as of 4.5 (Phase 12.6): a multi-subsystem isolation matrix proves the
reset isolates every framework subsystem across requests. See
[worker-mode.md](worker-mode.md) for the per-request vs boot state table, the
isolation guarantee, usage, and FrankenPHP / RoadRunner recipes.
