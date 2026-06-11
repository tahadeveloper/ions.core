# Worker mode

> **Status: STABLE (4.5).** The per-request reset lifecycle and
> `Ions\Runtime\WorkerRunner` were introduced in Phase 8.2 and are promoted to
> stable in Phase 12.6: a multi-subsystem **isolation matrix**
> (`tests/Feature/Runtime/WorkerLeakMatrixTest.php`) proves that
> `Kernel::resetForRequest()` isolates every framework subsystem added through
> 8.x–12.x. The host responsibilities at the bottom still apply. Classic
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
| Session **FlashBag** | inner session | **Cleared with the session** — un-consumed flash from request N never bleeds into request N+1 |
| Per-request Twig globals | `_csrf_token`, `_trans`, `appUrl` on `view.env` | **Re-evaluated** via `ViewFactory::refreshRequestGlobals()` (only when the environment is already built) |
| Eloquent query log | `config('database.query_log')` | **Flushed** when enabled, so it never grows across worker requests |
| Log correlation id | `Ions\Bundles\RequestIdProcessor` static | **Cleared** — the next log write (any channel) mints a fresh `extra.request_id` (see [logging.md](logging.md)) |
| Config | `Kernel::config()` | Kept |
| Container + singletons | `cache`, `db`, `jwt`, `gate`, `events`, `queue`, `view.env`, … | Kept |
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

## The isolation guarantee (12.6 leak matrix)

`tests/Feature/Runtime/WorkerLeakMatrixTest.php` boots the kernel **once** and
then drives two-or-more sequential `resetForRequest()` → `handle()` cycles
through fixture routes that *exercise* each subsystem added between 8.x and
12.x, asserting **no cross-request bleed**. Each subsystem is isolated by the
existing reset (no extra reset code was needed for any of them — the design
held):

| Subsystem (phase) | Why request B never sees request A's state |
| --- | --- |
| **Auth / JWT** (8.3) | `auth_user_id`/`auth_user` live on the request attributes; the request static is cleared and `handle()` re-points it. A guest B never sees A's user. |
| **Gate** (10.4) | The `gate` singleton resolves the user **lazily per check** from `Kernel::request()->attributes['auth_user']` and never caches it. `forUser()` scope lives on a *clone*, not the singleton. |
| **Flash** (10.3) | The consume memo lives on the per-request attribute bag (dies with the request); the FlashBag is part of the inner session, which `renew()` replaces. |
| **Session + CSRF** (8.2/8.4) | `renew()` swaps in a fresh inner session; A's session values and A's CSRF token no longer validate for B. |
| **Trusted proxies / hosts** (10.1/8.4) | `handle()` re-applies `applyTrustedProxies()` against **this** request; `isSecure()`/`getClientIp()` read B's own headers/peer. A trusted-proxy A does not make an untrusted-peer B inherit A's resolution. |
| **Query log** (8.1) | Flushed in `resetForRequest()` when enabled — B's log starts empty. |
| **Response cache** (12.5) | Stateless middleware/`ResponseCache` instances keyed by request; distinct URLs get distinct keys (and a stable key is a *cache hit*, by design). |
| **IonDisk overrides** (12.1) | Per-call bucket/basePath overrides are applied and restored in a `finally` within each call, so the static state never leaks. |
| **Scheduler** (9.4), **ORM-strict flags** (10.6) | Boot-time state, correctly **kept** across worker requests. |

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

### Worker recycling (`maxRequests`)

`maxRequests` is the memory-growth guard: after N handled requests the loop
returns and the SAPI restarts a fresh worker process. Set it to a value that
keeps per-process memory comfortable for your app (500–1000 is typical). It is
the backstop for *host-side* leaks (see "Host responsibilities") — the
framework's own per-request state is reset every iteration regardless.

## FrankenPHP recipe

FrankenPHP is **not** a dependency of `ionzile/core`; this is a doc-only
worker script (`public/worker.php`) wired through
`frankenphp_handle_request()`:

```php
<?php
// public/worker.php — FrankenPHP worker mode

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

## RoadRunner recipe

RoadRunner drives PHP workers over its own PSR-7 protocol; `spiral/roadrunner`
+ `spiral/roadrunner-http` (and `nyholm/psr7`) are the host-side deps. The
worker translates each PSR-7 request into an Ions `Request` and the Ions
response back to PSR-7. `WorkerRunner` carries the boot-once + reset loop:

```php
<?php
// worker.php — RoadRunner worker mode

use Ions\Runtime\WorkerRunner;
use Ions\Support\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

require __DIR__ . '/vendor/autoload.php';

$factory = new Psr17Factory();
$psr7 = new PSR7Worker(Worker::create(), $factory, $factory, $factory);

$toSymfony = new HttpFoundationFactory();                                  // PSR-7  → HttpFoundation
$toPsr7 = new PsrHttpFactory($factory, $factory, $factory, $factory);      // HttpFoundation → PSR-7

(new WorkerRunner(namespace: 'App\\Http\\', basePath: __DIR__))->run(
    requestProvider: function () use ($psr7, $toSymfony): ?Request {
        $psrRequest = $psr7->waitRequest();           // null when RoadRunner stops the worker
        if ($psrRequest === null) {
            return null;
        }

        return Request::createFromBase($toSymfony->createRequest($psrRequest));
    },
    responseEmitter: function (Response $response, Request $request) use ($psr7, $toPsr7): void {
        $psr7->respond($toPsr7->createResponse($response));
    },
    maxRequests: 500,                                  // recycle after 500 requests
);
```

With a `.rr.yaml`:

```yaml
version: "3"

server:
  command: "php worker.php"

http:
  address: 0.0.0.0:8080
  pool:
    num_workers: 4
    max_jobs: 500          # supervisor-side recycle, mirrors maxRequests
```

## Host responsibilities

`resetForRequest()` covers **framework-owned** state only. The following are on
the host application (and the runner's `maxRequests` is the backstop):

- **Host-app static state is NOT reset.** Avoid mutable `static` properties,
  memoized per-user data, or service singletons that capture request data in
  your controllers/services — they will bleed between requests.
- **Native PHP sessions** (`session.driver = native`) are fragile in worker
  runtimes: `renew()` closes and restarts `NativeSessionStorage`, but PHP's
  native session machinery was designed for one request per process. Prefer a
  non-native driver (or a custom storage backed by cache/database) for worker
  deployments. `ions doctor` emits a `worker_mode` INFO row that flags a native
  driver.
- **Auth/JWT revocation**: the framework binds a persistent file-cache-backed
  `revocation_store` by default, which is worker-safe. If a host *replaces* it
  with the in-memory `ArrayRevocationStore`, revocations would live for the
  whole worker (not a single request) and not survive a restart — bind a
  persistent (cache/database/Redis) store instead.
- **Query logging** (`database.query_log`) is flushed per request, but leaving
  it enabled in production workers still costs memory within each request —
  keep it off outside debugging.
- `Kernel::resetForRequest()` re-captures the shared request from superglobals
  as a placeholder; the real per-request sync happens inside
  `Kernel::handle()`, which points `Kernel::request()` at the request being
  handled.
