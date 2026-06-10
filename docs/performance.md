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
| `ions optimize` | `route:cache` + `config:cache` in one shot (deploy hook). |
| `ions optimize:clear` | `route:clear` + `config:clear` + deletes the compiled Twig cache (`var/cache/twig`). |
| `ions preload:generate` | Writes `var/cache/preload.php`, an `opcache.preload` file compiling the framework hot path. |

### When the caches apply

- **Never in debug.** When `APP_DEBUG` is truthy the kernel always reads live
  routes and config — you cannot serve a stale cache while developing.
- Otherwise the kernel uses a cache **iff its file exists**: matching goes
  through `CompiledUrlMatcher` (no route file parse, no attribute scan), and
  boot loads the single merged config array with one `require`.
- Commands that need the real `RouteCollection` (`route:list`,
  `openapi:generate`) always build live — only request *matching* uses the
  compiled cache.

### Invalidation

The caches are plain files — they never invalidate themselves. Re-run
`ions optimize` (or `route:cache` / `config:cache`) after **every deploy** and
after any change to routes, controllers with route attributes, or `config/`.
`optimize:clear` returns you to fully-live behavior. In-process memoized state
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

## opcache preload (optional)

`ions preload:generate` emits a curated `opcache_compile_file()` list (kernel,
container, providers, middleware, routing, request/response). Point php.ini at
it:

```ini
opcache.preload=/path/to/host/var/cache/preload.php
opcache.preload_user=www-data
```

Regenerate after upgrading `ionzile/core`.

## Measured impact (fixtures, php83)

| Step | Metric | Before | After |
| --- | --- | --- | --- |
| Route capture once per process | steady-state `handle()` /ping | 0.218 ms/req | 0.084 ms/req (~61% faster) |
| + compiled route cache | cold boot + first request | 1.29 ms | 0.99 ms (first-match cost ~0.79 → ~0.20 ms) |
| + config cache | boot | 0.73 ms | 0.33 ms (~54% faster) |
| Twig `view.env` singleton | no-override `ViewFactory::make()` | 0.060–0.069 ms | ~0.001 ms |

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
style) builds on these per-process caches and lands in Phase 8.2 with a
`resetForRequest()` lifecycle. The per-process memoization introduced here is
already worker-safe: re-booting the kernel clears every memoized collection
and singleton.
