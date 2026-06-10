# Worker mode (experimental)

> **Status: EXPERIMENTAL (4.1).** The per-request reset lifecycle and
> `Ions\Runtime\WorkerRunner` are new in Phase 8.2. The API may change in a
> minor release, and the limitations listed at the bottom apply. Classic
> one-process-per-request deployments (php-fpm/CGI) are unaffected — nothing
> here is required for them.

In a persistent worker runtime (FrankenPHP, RoadRunner, Swoole — or any loop
that calls `Kernel::handle()` more than once in one process) the framework
boots **once** and then handles **many** requests. Phase 8.1 made boot state
cacheable per process; Phase 8.2 makes the *per-request* state resettable so
no request can see another request's data.

## `Kernel::resetForRequest()`

Call it **before each request** (the `WorkerRunner` does this for you). It
draws a hard line between per-request state (cleared) and boot state (kept):

| State | Where | On `resetForRequest()` |
| --- | --- | --- |
| Shared `Request` static | `Kernel::$request` | **Cleared** — rebuilt fresh (and `handle()` re-points it at the request actually being handled) |
| Shared `Response` static | `Kernel::$response` | **Cleared** — fresh `Response` (closes the closure-fallback leak) |
| Legacy session static | `Kernel::$session` | **Cleared** — fresh `Ions\Support\Session` |
| Framework session | `session` (`SessionManager`) | **Cleared** — `SessionManager::renew()` swaps in a brand-new inner Symfony session (fresh storage + bags); the manager binding itself survives |
| CSRF token storage | `csrf` via `request_stack` | **Follows the session** — the request on the shared `RequestStack` is re-pointed at the new session, and `SessionTokenStorage` reads through that stack |
| Per-request Twig globals | `_csrf_token`, `_trans`, `appUrl` on `view.env` | **Re-evaluated** via `ViewFactory::refreshRequestGlobals()` (only when the environment is already built) |
| Eloquent query log | `config('database.query_log')` | **Flushed** when enabled, so it never grows across worker requests |
| Config | `Kernel::config()` | Kept |
| Container + singletons | `cache`, `db`, `jwt`, `events`, `queue`, `view.env`, … | Kept |
| Route memo + compiled route cache | per-group, from 8.1 | Kept — no route re-capture per request |
| Twig `Environment` object | `view.env` | Kept (globals refreshed, not rebuilt) |

### How the session swap works

The `session` binding is a `SessionManager` *wrapper* around a Symfony
`Session`. `renew()` saves the started session (so the native driver persists
request N's data), then replaces the inner `Session` with a new one built from
the same `config('session')` driver. Everything that holds the **manager**
(the container, `session()` helper, `StartSessionMiddleware`) keeps working;
everything that held the **inner session** is re-pointed:

- the shared `request_stack`'s current request gets `setSession(newSession)`,
  so the CSRF manager (whose `SessionTokenStorage` resolves the session
  through that stack on every call) automatically issues/validates tokens
  against the new session;
- `StartSessionMiddleware` attaches `manager->getSession()` to each handled
  request, so the next request carries the new session from the start.

### Twig globals: why re-registration (not lazy)

`_csrf_token`, `_trans` and `appUrl` are Twig **globals** — plain values
frozen when the shared `view.env` is built. Lazy per-access evaluation was
considered but rejected: Twig globals cannot be closures, and wrapping them in
stringable proxy objects changes their type inside templates (`{% if _trans %}`
truthiness, string comparisons) — a silent BC break. Instead,
`ViewFactory::refreshRequestGlobals()` re-evaluates the three values and
re-sets them (Twig allows updating an *existing* global after initialization),
and `Kernel::resetForRequest()` invokes it on the already-built environment.
The isolation test suite proves `_csrf_token` reflects the new session after a
reset and validates against the live CSRF manager.

## `Ions\Runtime\WorkerRunner`

A runtime-agnostic loop: pull a request from a *provider* callable, reset,
handle, push the response to an *emitter* callable.

```php
use Ions\Runtime\WorkerRunner;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

$runner = new WorkerRunner(
    namespace: 'App\\Http\\',          // forwarded to Kernel::handle()
    basePath: '/var/www/my-app',       // used only if the kernel is not booted yet
);

$handled = $runner->run(
    requestProvider: function (): ?Request {
        // Return the next request, or null to stop the loop.
    },
    responseEmitter: function (Response $response, Request $request): void {
        // Hand the response back to the runtime.
    },
    maxRequests: 500,                   // optional worker recycling (null = unlimited)
);
```

`run()` boots the kernel if needed, then per iteration calls
`Kernel::resetForRequest()` → `Kernel::handle($request)` → your emitter. It
returns the number of requests handled, so a supervisor can recycle the worker
after `maxRequests` (bounding any slow leak in host code).

### FrankenPHP adapter example

FrankenPHP is **not** a dependency of `ionzile/core`; this is a doc-only
example of a worker script (`public/worker.php`) wired through
`frankenphp_handle_request()`:

```php
<?php
// public/worker.php — FrankenPHP worker mode (EXPERIMENTAL)

use Ions\Foundation\Kernel;
use Ions\Support\Request;

require __DIR__ . '/../vendor/autoload.php';

Kernel::boot(dirname(__DIR__));

$handler = static function (): void {
    Kernel::resetForRequest();
    // Superglobals are repopulated by FrankenPHP for each request.
    $request = Request::createFromBase(Request::createFromGlobals());
    Kernel::sendResponse(Kernel::handle($request));
};

$maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 500);
for ($handled = 0; $handled < $maxRequests; $handled++) {
    $keepRunning = \frankenphp_handle_request($handler);
    gc_collect_cycles();
    if (!$keepRunning) {
        break;
    }
}
```

With a `Caddyfile` along these lines:

```caddyfile
{
    frankenphp {
        worker ./public/worker.php
    }
}

example.com {
    root * public
    php_server
}
```

## Known limitations

- **EXPERIMENTAL** — covered by tests for sequential isolation in one process,
  but not yet battle-tested under real FrankenPHP/RoadRunner load.
- **Native PHP sessions** (`session.driver = native`) are fragile in worker
  runtimes: `renew()` closes and restarts `NativeSessionStorage`, but PHP's
  native session machinery was designed for one request per process. Prefer a
  non-native driver (or a custom storage backed by cache/database) for worker
  deployments.
- **Host-app static state is NOT reset.** `resetForRequest()` covers
  framework-owned state only. Avoid mutable `static` properties, memoized
  per-user data, or service singletons that capture request data in your
  controllers/services — they will bleed between requests.
- **Auth/JWT revocation**: the default `ArrayRevocationStore` is in-memory and
  now lives for the *worker's* lifetime, not a single request. Bind a
  persistent `revocation_store` (cache/database) in worker deployments.
- **Query logging** (`database.query_log`) is flushed per request, but leaving
  it enabled in production workers still costs memory within each request —
  keep it off outside debugging.
- `Kernel::resetForRequest()` re-captures the shared request from superglobals
  as a placeholder; the real per-request sync happens inside
  `Kernel::handle()`, which points `Kernel::request()` at the request being
  handled.
