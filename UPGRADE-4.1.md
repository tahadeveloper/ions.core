# Upgrading to 4.1 (draft — release notes assembled in Phase 8.7)

## Behavior changes

### Query logging is no longer implied by APP_DEBUG

Before 4.1, `DatabaseProvider::boot()` called `enableQueryLog()` on the
default connection whenever `APP_DEBUG` was truthy. The query log buffers
every executed statement in memory for the lifetime of the process, which
grows without bound in long-running processes.

In 4.1 the log is **opt-in**:

```php
// config/database.php
return [
    'default' => 'mysql',
    'query_log' => true,   // explicit opt-in (default false)
    // ...
];
```

If you relied on `APP_DEBUG=true` to make `debugQuery()` return statements,
add `'query_log' => true` to `config/database.php`.

### Routes are captured once per process

`Kernel::handle()` no longer re-requires the route files and re-scans
attribute routes on every call; the per-group collection is memoized for the
process lifetime and rebuilt on every `Kernel::boot()`. Classic FPM apps see
no functional difference (one process = one request); code that mutated
`Kernel::RouteCollection()` *between* `handle()` calls in the same process
must re-boot the kernel to have the change picked up.

## New (optional) production caches

```bash
ions optimize           # route:cache + config:cache
ions optimize:clear     # clears both + the compiled Twig cache
ions route:cache        # compiled route matching (CompiledUrlMatcher)
ions config:cache       # one merged config file
ions preload:generate   # opcache.preload file for the framework hot path
```

All caches are ignored while `APP_DEBUG` is truthy. Closure-based routes or
config values cannot be cached — the commands fail with the offending
route/key named. See [docs/performance.md](docs/performance.md).
