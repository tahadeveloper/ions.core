# Phase 2 — DI Container, Service Providers & Middleware Pipeline (Implementation Plan)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn `Kernel` from a static god-object into a thin orchestrator that builds a PSR-11 container, runs config-driven service providers, and dispatches each request through a middleware pipeline to a container-resolved controller — while keeping the existing `Ions\` public surface (helpers, `Kernel::app/config/session/request/response`, `Route`, controller lifecycle hooks) working via BC shims.

**Architecture:** `Ions\Container\Container` extends `Illuminate\Container\Container` (so it already satisfies `Psr\Container\ContainerInterface` and keeps `bind/singleton/make`). `ServiceProvider`s (config-driven list) own all wiring that today lives inline in `Kernel`/`RegisterDB`/`BaseController`. A `Pipeline` runs `MiddlewareInterface` objects (`handle(Request, callable $next): Response`) around a `ControllerDispatcher` terminal. The Phase-1 security primitives (`SecurityHeaders`, trusted hosts, JWT auth, CSRF) move into composable middleware, which finally makes the auth gate unit-testable (closing the Task 1.4 deferral). `Kernel::handle(Request): Response` returns a response; `Kernel::run()` sends it; `Kernel::make()` stays as a BC shim.

**Tech Stack:** PHP 8.2+, Illuminate 9 Container (pinned; upgraded in Phase 4), Symfony HttpFoundation + Routing (already used), `psr/container` (already transitively present), Pest 2 + PHPStan 2 (existing toolchain & baseline). No new runtime deps expected; confirm `psr/container` is directly required.

**Branch:** Do this work on a fresh branch off `main` (e.g. `phase2-container`). `main` already contains Phase 0+1.

---

## How to read this plan

Six sequenced sub-phases, each shippable and independently reviewable:

- **2.1 Container**, **2.2 ServiceProvider + bootstrap**, **2.3 Pipeline** — foundational, low-risk, **fully specified with TDD + code**.
- **2.4 Security middleware** (incl. the deferred auth integration tests) — fully specified.
- **2.5 `Kernel::handle()` refactor + BC shims** — the integration task; detailed steps + code sketches, with explicit acceptance tests. The implementer has latitude on exact wiring but must preserve BC and pass the end-to-end test.
- **2.6 Remaining providers** (Config/View/Mail/Auth/Routing) — incremental migration; concrete per-provider specs. May be split into its own follow-up plan if 2.5 reveals churn.

**Guardrails for every task:** keep `composer qa` green (cs + stan + the existing 38 tests must never regress); new code must be PHPStan-clean (no new baseline suppressions — only the legacy baseline may shrink); follow the existing `Singleton`/static patterns only where required for BC, preferring container injection for new code.

---

## Target end-state (request lifecycle)

```
Kernel::boot(?basePath)
  ├─ load .env, build Ions\Container\Container (Facade root)
  ├─ captureConfig()  (until 2.6 moves it to ConfigProvider)
  ├─ instantiate providers from config('app.providers', defaults)
  ├─ register() all, then boot() all
  └─ include helpers, preloads

Kernel::run()                          ← host front controller calls this (was make())
  └─ send(Kernel::handle(captureRequest()))

Kernel::handle(Request): Response
  ├─ build RouteCollection (php|yaml + attributes)  [unchanged]
  ├─ match → controller string + args               [unchanged matcher]
  ├─ select middleware stack (web|api) from config('app.middleware')
  └─ Pipeline(stack, terminal: ControllerDispatcher)->handle(Request): Response
        TrustedHost → SecurityHeaders → Cors → (Csrf web | Auth api) → ControllerDispatcher
```

---

## File structure

**New:**
- `src/Container/Container.php` — extends `Illuminate\Container\Container`; typed helpers.
- `src/Container/ServiceProvider.php` — abstract base (`register()`, `boot()`).
- `src/Providers/FilesystemProvider.php`, `src/Providers/DatabaseProvider.php` (2.2); `ConfigProvider`, `ViewProvider`, `MailProvider`, `AuthProvider`, `RoutingProvider` (2.6).
- `src/Http/Middleware/MiddlewareInterface.php`
- `src/Http/Middleware/Pipeline.php`
- `src/Http/Middleware/ControllerDispatcher.php`
- `src/Http/Middleware/{TrustedHost,SecurityHeaders,Cors,Csrf,Auth}Middleware.php`
- `tests/Unit/Container/*`, `tests/Unit/Http/Middleware/*`, `tests/Feature/HandleTest.php`.

**Modified:**
- `src/Foundation/Kernel.php` — container build, provider bootstrap, `handle()`/`run()` split, BC shims.
- `src/Foundation/RegisterDB.php` — logic moves to `DatabaseProvider`; `boot()` becomes a delegating shim.
- `src/Foundation/{Api,Base}Controller.php` — auth lifts out of `ApiController::__construct` into `AuthMiddleware` (2.4/2.5).
- `composer.json` — ensure `psr/container` is a direct require.

---

## Sub-phase 2.1 — Container

### Task 2.1.1: `Ions\Container\Container`

**Files:** Create `src/Container/Container.php`, `tests/Unit/Container/ContainerTest.php`.

- [ ] **Step 1: Failing test.**
```php
<?php

use Ions\Container\Container;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

test('is a PSR-11 container', function () {
    expect(new Container())->toBeInstanceOf(ContainerInterface::class);
});

test('binds and resolves a factory', function () {
    $c = new Container();
    $c->bind('greeting', fn () => 'hi');
    expect($c->get('greeting'))->toBe('hi');
});

test('singleton returns the same instance', function () {
    $c = new Container();
    $c->singleton('svc', fn () => new stdClass());
    expect($c->get('svc'))->toBe($c->get('svc'));
});

test('has() reflects bindings', function () {
    $c = new Container();
    expect($c->has('missing'))->toBeFalse();
    $c->instance('present', new stdClass());
    expect($c->has('present'))->toBeTrue();
});

test('get() on an unresolvable, unbound id throws a PSR NotFound', function () {
    $c = new Container();
    expect(fn () => $c->get('definitely-not-bound-xyz'))
        ->toThrow(NotFoundExceptionInterface::class);
});
```

- [ ] **Step 2: Run → FAIL.** `vendor/bin/pest tests/Unit/Container/ContainerTest.php`

- [ ] **Step 3: Implement.**
```php
<?php

namespace Ions\Container;

use Illuminate\Container\Container as IlluminateContainer;

/**
 * The Ions service container. Extends Illuminate's container (which already
 * implements Psr\Container\ContainerInterface and provides bind/singleton/make)
 * so existing Kernel::app()->make()/singleton() call sites keep working.
 */
class Container extends IlluminateContainer
{
}
```
> Illuminate 9's `Container` already implements `Psr\Container\ContainerInterface` and throws `EntryNotFoundException` (a `NotFoundExceptionInterface`) from `get()` for an unresolvable id. Verify this against the installed version; if `get()` on a truly-unbound *unresolvable* id doesn't throw NotFound (Illuminate will try to auto-resolve class names), adjust the test to use an id that is not a resolvable class (a plain string like `'definitely-not-bound-xyz'` is not instantiable, so `make()` throws `BindingResolutionException`, which Illuminate's `get()` wraps as `EntryNotFoundException`). Confirm the actual behavior and keep the test asserting the PSR interface.

- [ ] **Step 4: Run → PASS. Commit.**
```bash
git add src/Container/Container.php tests/Unit/Container/ContainerTest.php
git commit -m "feat(container): add Ions\\Container\\Container (PSR-11 over Illuminate)"
```

### Task 2.1.2: Kernel builds the Ions container (BC-preserving swap)

**Files:** Modify `src/Foundation/Kernel.php`; add `tests/Feature/ContainerBootTest.php`.

- [ ] **Step 1: Failing test (container type after boot).**
```php
<?php

test('the booted app container is an Ions container and still resolves filesystem', function () {
    bootFixtureKernel();
    expect(\Ions\Foundation\Kernel::app())->toBeInstanceOf(\Ions\Container\Container::class)
        ->and(\Ions\Foundation\Kernel::app()->has('filesystem'))->toBeTrue();
});
```

- [ ] **Step 2: Run → FAIL** (Kernel still builds raw `Illuminate\Container\Container`).

- [ ] **Step 3:** In `Kernel::Container()` replace `static::$app = new Container();` (the Illuminate one) with `static::$app = new \Ions\Container\Container();`. Update the `use Illuminate\Container\Container;` import and the `protected static Container $app;` property type to `\Ions\Container\Container`. Leave the `filesystem`/`files` singleton bindings exactly as they are for now (they move to `FilesystemProvider` in 2.2). `Kernel::app()` keeps returning the container — now an `Ions\Container\Container` (still an Illuminate container, so all `make()/singleton()` callers and the `app()` helper are unaffected).

- [ ] **Step 4: Run full suite → PASS (39 tests). `composer qa` green. Commit.**
```bash
git commit -am "refactor(kernel): build Ions\\Container\\Container instead of raw Illuminate container"
```

---

## Sub-phase 2.2 — Service providers + bootstrap

### Task 2.2.1: `ServiceProvider` base

**Files:** Create `src/Container/ServiceProvider.php`, `tests/Unit/Container/ServiceProviderTest.php`.

- [ ] **Step 1: Failing test.**
```php
<?php

use Ions\Container\Container;
use Ions\Container\ServiceProvider;

test('register binds into the container; boot runs after', function () {
    $c = new Container();
    $provider = new class ($c) extends ServiceProvider {
        public bool $booted = false;
        public function register(): void { $this->container->instance('thing', 'value'); }
        public function boot(): void { $this->booted = true; }
    };
    $provider->register();
    expect($c->get('thing'))->toBe('value');
    expect($provider->booted)->toBeFalse();
    $provider->boot();
    expect($provider->booted)->toBeTrue();
});
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement.**
```php
<?php

namespace Ions\Container;

abstract class ServiceProvider
{
    public function __construct(protected Container $container)
    {
    }

    /** Bind services into the container. Runs for ALL providers before any boot(). */
    abstract public function register(): void;

    /** Side-effecting startup that may depend on other providers' bindings. */
    public function boot(): void
    {
    }
}
```

- [ ] **Step 4: Run → PASS. Commit.**
```bash
git commit -am "feat(container): add ServiceProvider base (register/boot two-pass)"
```

### Task 2.2.2: `FilesystemProvider` (port the inline filesystem bindings)

**Files:** Create `src/Providers/FilesystemProvider.php`, `tests/Unit/Providers/FilesystemProviderTest.php`.

- [ ] **Step 1: Failing test.**
```php
<?php

use Ions\Container\Container;
use Ions\Providers\FilesystemProvider;

test('registers filesystem and files singletons', function () {
    $c = new Container();
    (new FilesystemProvider($c))->register();
    expect($c->get('filesystem'))->toBeInstanceOf(\Illuminate\Filesystem\Filesystem::class)
        ->and($c->get('files'))->toBe($c->get('files')); // singleton identity
});
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement** (move the bindings out of `Kernel::Container()`):
```php
<?php

namespace Ions\Providers;

use Illuminate\Filesystem\Filesystem;
use Ions\Container\ServiceProvider;

final class FilesystemProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!$this->container->bound('filesystem')) {
            $this->container->singleton('filesystem', fn () => new Filesystem());
        }
        if (!$this->container->bound('files')) {
            $this->container->singleton('files', fn () => new Filesystem());
        }
    }
}
```
> Use `bound()` (Illuminate) rather than `has()` so we only register if not already bound, preserving the existing guard semantics.

- [ ] **Step 4: Run → PASS. Commit.**
```bash
git commit -am "feat(providers): FilesystemProvider for filesystem/files bindings"
```

### Task 2.2.3: `DatabaseProvider` (port `RegisterDB`; keep `RegisterDB::boot` as shim)

**Files:** Create `src/Providers/DatabaseProvider.php`, `tests/Feature/DatabaseProviderTest.php`; modify `src/Foundation/RegisterDB.php`.

- [ ] **Step 1: Failing test** (uses the sqlite fixture, which sets `database_engine=['db']`):
```php
<?php

test('database provider binds an eloquent-capable db manager', function () {
    bootFixtureKernel();
    $app = \Ions\Foundation\Kernel::app();
    expect($app->has('db'))->toBeTrue();
    // a trivial query proves the connection is wired
    $result = \Ions\Support\DB::connection()->select('select 1 as one');
    expect($result[0]->one)->toBe(1);
});
```

- [ ] **Step 2: Run → FAIL** (db isn't bound at boot yet — currently only bound when a controller calls `RegisterDB::boot()`).

- [ ] **Step 3: Implement `DatabaseProvider`** by moving the body of `RegisterDB::DBConnections()` / `redBeanConnection()` into the provider's `register()` (bindings) and `boot()` (`bootEloquent()`, redbean setup, query log). Gate on `config('app.database_engine', [])` exactly as today. Then reduce `RegisterDB::boot()` to a deprecated shim:
```php
/** @deprecated The DatabaseProvider now wires the DB at Kernel boot. Kept for BC. */
public static function boot(): void
{
    if (!Kernel::app()->bound('db') && in_array('db', (array) config('app.database_engine', []), true)) {
        (new \Ions\Providers\DatabaseProvider(Kernel::app()))->register();
        (new \Ions\Providers\DatabaseProvider(Kernel::app()))->boot();
    }
    // redbean idempotency similarly guarded
}
```
> The controllers still call `RegisterDB::boot()` in their constructors; the shim makes that a no-op once the provider has already booted, so behavior is unchanged for existing apps while new flows get the DB from the container.

- [ ] **Step 4: Run → PASS. `composer qa` green. Commit.**
```bash
git commit -am "feat(providers): DatabaseProvider; RegisterDB::boot becomes a BC shim"
```

### Task 2.2.4: Provider bootstrap in `Kernel::boot()`

**Files:** Modify `src/Foundation/Kernel.php`; add `tests/Feature/ProviderBootstrapTest.php`.

- [ ] **Step 1: Failing test** — after boot, both default providers have run (filesystem + db bound without any controller being instantiated):
```php
<?php

test('default providers are registered and booted during Kernel::boot', function () {
    bootFixtureKernel();
    $app = \Ions\Foundation\Kernel::app();
    expect($app->has('filesystem'))->toBeTrue()
        ->and($app->has('db'))->toBeTrue();
});
```

- [ ] **Step 2: Run → FAIL** (db only bound via the RegisterDB shim today).

- [ ] **Step 3: Implement** in `Kernel::boot()`, AFTER `captureConfig()` and the helpers include (so `config()` works), BEFORE `preloads()`:
```php
static::bootProviders();
```
and add:
```php
/** @return class-string<\Ions\Container\ServiceProvider>[] */
private static function defaultProviders(): array
{
    return [
        \Ions\Providers\FilesystemProvider::class,
        \Ions\Providers\DatabaseProvider::class,
    ];
}

private static function bootProviders(): void
{
    /** @var class-string<\Ions\Container\ServiceProvider>[] $classes */
    $classes = config('app.providers', static::defaultProviders());
    $providers = array_map(fn ($c) => new $c(static::$app), $classes);
    foreach ($providers as $p) { $p->register(); }
    foreach ($providers as $p) { $p->boot(); }
}
```
> Note: `FilesystemProvider` now also covers what `Kernel::Container()` did inline — remove the inline `filesystem`/`files` bindings from `Container()` so there's one source of truth (the `bound()` guards make double-registration harmless either way, but keep it DRY). Existing apps that set their own `app.providers` must include these defaults — document in the upgrade guide that omitting `app.providers` uses the defaults, and setting it REPLACES them (so apps should spread the defaults).

- [ ] **Step 4: Run → PASS. `composer qa` green. Commit.**
```bash
git commit -am "feat(kernel): bootstrap config-driven service providers (register then boot)"
```

---

## Sub-phase 2.3 — Middleware pipeline

### Task 2.3.1: `MiddlewareInterface` + `Pipeline`

**Files:** Create `src/Http/Middleware/MiddlewareInterface.php`, `src/Http/Middleware/Pipeline.php`, `tests/Unit/Http/Middleware/PipelineTest.php`.

- [ ] **Step 1: Failing tests.**
```php
<?php

use Ions\Http\Middleware\MiddlewareInterface;
use Ions\Http\Middleware\Pipeline;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

function passThrough(string $tag): MiddlewareInterface {
    return new class ($tag) implements MiddlewareInterface {
        public function __construct(private string $tag) {}
        public function handle(Request $request, callable $next): Response {
            $request->attributes->set($this->tag, true);
            $response = $next($request);
            $response->headers->set('X-Mw-' . $this->tag, '1');
            return $response;
        }
    };
}

test('runs middleware in order around the terminal and back out', function () {
    $order = [];
    $a = new class ($order) implements MiddlewareInterface {
        public function __construct(public array &$order) {}
        public function handle(Request $r, callable $next): Response { $this->order[] = 'a-in'; $res = $next($r); $this->order[] = 'a-out'; return $res; }
    };
    $b = new class ($order) implements MiddlewareInterface {
        public function __construct(public array &$order) {}
        public function handle(Request $r, callable $next): Response { $this->order[] = 'b-in'; $res = $next($r); $this->order[] = 'b-out'; return $res; }
    };
    $terminal = function (Request $r) use (&$order) { $order[] = 'terminal'; return new Response('ok'); };

    $response = (new Pipeline([$a, $b], $terminal))->handle(Request::create('/'));
    expect($response->getContent())->toBe('ok')
        ->and($order)->toBe(['a-in', 'b-in', 'terminal', 'b-out', 'a-out']);
});

test('a middleware can short-circuit without calling next', function () {
    $blocker = new class implements MiddlewareInterface {
        public function handle(Request $r, callable $next): Response { return new Response('blocked', 403); }
    };
    $terminal = fn (Request $r) => new Response('should not run', 200);
    $response = (new Pipeline([$blocker], $terminal))->handle(Request::create('/'));
    expect($response->getStatusCode())->toBe(403)->and($response->getContent())->toBe('blocked');
});
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement.**
```php
<?php
// src/Http/Middleware/MiddlewareInterface.php
namespace Ions\Http\Middleware;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

interface MiddlewareInterface
{
    /** @param callable(Request):Response $next */
    public function handle(Request $request, callable $next): Response;
}
```
```php
<?php
// src/Http/Middleware/Pipeline.php
namespace Ions\Http\Middleware;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

final class Pipeline
{
    /**
     * @param MiddlewareInterface[] $middleware
     * @param callable(Request):Response $terminal
     */
    public function __construct(private array $middleware, private $terminal)
    {
    }

    public function handle(Request $request): Response
    {
        $chain = array_reduce(
            array_reverse($this->middleware),
            fn (callable $next, MiddlewareInterface $mw): callable
                => fn (Request $req): Response => $mw->handle($req, $next),
            $this->terminal,
        );
        return $chain($request);
    }
}
```

- [ ] **Step 4: Run → PASS. Commit.**
```bash
git commit -m "feat(http): middleware interface + onion Pipeline"
```

---

## Sub-phase 2.4 — Security middleware (closes Task 1.4 auth-test deferral)

Each middleware wraps a Phase-1 primitive and is unit-tested in isolation (now possible). Build them one task each; pattern identical, so only the two non-trivial ones (Auth, Csrf) get full code here — the others mirror them.

### Task 2.4.1: `SecurityHeadersMiddleware` + `TrustedHostMiddleware` + `CorsMiddleware`

**Files:** the three middleware classes + `tests/Unit/Http/Middleware/SecurityHeadersMiddlewareTest.php` (and analogous).

- [ ] **SecurityHeadersMiddleware:** `handle()` calls `$response = $next($request); return SecurityHeaders::apply($response);`. Test: response coming back through it has `X-Content-Type-Options: nosniff`.
- [ ] **TrustedHostMiddleware:** constructor takes the host patterns (from `config('app.trusted_hosts')`); `handle()` calls `Request::setTrustedHosts($patterns)` if non-empty then `$next($request)`. Test: with a pattern set and an untrusted Host, `$request->getHost()` inside the terminal throws `SuspiciousOperationException` (reset global state in `afterEach`, no braces in patterns — see Task 1.3 lesson).
- [ ] **CorsMiddleware:** reads allowed origins/methods/headers from `config('app.cors', [...])`; sets `Access-Control-*` headers on the response; short-circuits `OPTIONS` preflight with a 204. Test: preflight returns 204 with the configured `Access-Control-Allow-Methods`. (This restores the CORS handling that was commented out in `ApiController`.)
- [ ] Commit each (or as one `feat(http): security headers/trusted-host/cors middleware`).

### Task 2.4.2: `AuthMiddleware` (lift auth out of `ApiController::__construct`) — INCLUDES the deferred auth integration tests

**Files:** Create `src/Http/Middleware/AuthMiddleware.php`, `tests/Unit/Http/Middleware/AuthMiddlewareTest.php`.

- [ ] **Step 1: Failing tests — the auth gate, now directly testable (these are the Task 1.4 / D-deferred tests):**
```php
<?php

use Ions\Http\Middleware\AuthMiddleware;
use Ions\Security\Jwt;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->secret = str_repeat('k', 32);
    $this->jwt = new Jwt($this->secret, 'ions', 'ions', 3600);
    $this->mw = new AuthMiddleware($this->jwt);
    $this->terminal = fn (Request $r) => new Response('ok', 200);
});

test('missing Authorization header → 401', function () {
    $res = $this->mw->handle(Request::create('/api'), $this->terminal);
    expect($res->getStatusCode())->toBe(401);
});

test('non-Bearer scheme → 401', function () {
    $req = Request::create('/api'); $req->headers->set('Authorization', 'Basic abc');
    expect($this->mw->handle($req, $this->terminal)->getStatusCode())->toBe(401);
});

test('expired token → 401', function () {
    $expiredToken = (new Jwt($this->secret, 'ions', 'ions', -10))->issue('7');
    $req = Request::create('/api'); $req->headers->set('Authorization', 'Bearer ' . $expiredToken);
    expect($this->mw->handle($req, $this->terminal)->getStatusCode())->toBe(401);
});

test('valid token → passes through and attaches auth_user_id', function () {
    $token = $this->jwt->issue('42');
    $req = Request::create('/api'); $req->headers->set('Authorization', 'Bearer ' . $token);
    $res = $this->mw->handle($req, $this->terminal);
    expect($res->getStatusCode())->toBe(200)
        ->and($req->attributes->get('auth_user_id'))->toBe('42');
});

test('case-insensitive bearer scheme is accepted', function () {
    $token = $this->jwt->issue('9');
    $req = Request::create('/api'); $req->headers->set('Authorization', 'bearer ' . $token);
    expect($this->mw->handle($req, $this->terminal)->getStatusCode())->toBe(200);
});
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement** by moving the Bearer-parse + `Jwt::verify` logic out of `ApiController::isAuthorized()` into the middleware:
```php
<?php

namespace Ions\Http\Middleware;

use Ions\Security\Jwt;
use Ions\Security\TokenException;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private ?Jwt $jwt)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->jwt === null) {
            return $this->unauthorized('No signing key configured');
        }
        $header = (string) $request->headers->get('Authorization');
        if ($header === '') {
            return $this->unauthorized('Missing Authorization header');
        }
        $parts = explode(' ', $header, 2);
        if (count($parts) !== 2 || strtolower($parts[0]) !== 'bearer') {
            return $this->unauthorized('Expected a Bearer token');
        }
        try {
            $claims = $this->jwt->verify($parts[1]);
        } catch (TokenException) {
            return $this->unauthorized('Invalid or expired token');
        }
        $request->attributes->set('auth_user_id', $claims->userId);
        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return new JsonResponse(['status' => 'error', 'message' => 'Not authorized!', 'detail' => $message], 401);
    }
}
```
> `AuthMiddleware` is constructed by `AuthProvider`/`Kernel` with a `Jwt` built from `env('APP_KEY')` + `env('APP_NAME')` + `config('app.jwt.ttl')` (returning `null` Jwt when the key is missing/short — mirror Task 1.1's safe degradation). Wire the **D5-A clock leeway** param here when Phase 5 adds it.

- [ ] **Step 4: Run → PASS. Commit.**
```bash
git commit -m "feat(http): AuthMiddleware (Bearer+JWT) with the deferred auth gate tests"
```

### Task 2.4.3: `CsrfMiddleware`

- [ ] Wrap the existing `csrfCheck()` helper: on state-changing web methods (POST/PUT/PATCH/DELETE), validate the `_ion_token` (or configured field) and 419/403 on failure; otherwise `$next`. Test: valid token passes, missing/invalid token → 419. Reuse the Symfony CSRF storage already used by `csrfToken()`/`ionToken()`. Commit.

---

## Sub-phase 2.5 — `Kernel::handle()` refactor + BC shims (integration)

> This is the integration task. Steps are detailed but the implementer has latitude on exact wiring; the **non-negotiables** are: (a) `Kernel::make()` still works for existing front controllers, (b) the end-to-end `HandleTest` passes, (c) `composer qa` stays green, (d) controllers are resolved through the container.

### Task 2.5.1: `ControllerDispatcher` (the pipeline terminal)

**Files:** Create `src/Http/Middleware/ControllerDispatcher.php`; `tests/Unit/Http/Middleware/ControllerDispatcherTest.php`.

- [ ] Move the body of `Kernel::instanceTheController()` into a `ControllerDispatcher` that: resolves the controller via `Kernel::app()->make($controllerClass)` (container injection) instead of `new $controllerClass()`; runs the lifecycle hooks `_initState → _loadInit → _loadedState → action → _endState` exactly as today; and returns the shared `Kernel::response()` (or a controller-returned `Response` once Phase 3 adds that). Closures (`_controller instanceof Closure`) are invoked as today.
- [ ] Test with a fake controller class (in the test) implementing the hooks, asserting hook order and that the action ran. Commit.

### Task 2.5.2: `Kernel::handle(Request): Response` + `run()` + `make()` shim

**Files:** Modify `src/Foundation/Kernel.php`; add `tests/Feature/HandleTest.php`.

- [ ] **Step 1: Failing end-to-end test.** Add a route + controller to the fixture app (`tests/fixtures/app/routes/web.php` registering a closure or a tiny controller under the fixture's `Http/Controllers`), then:
```php
<?php

test('handle() runs the web pipeline and returns a Response with hardening headers', function () {
    bootFixtureKernel();
    $request = \Ions\Support\Request::create('/ping'); // fixture route returns "pong"
    $response = \Ions\Foundation\Kernel::handle($request);
    expect($response)->toBeInstanceOf(\Symfony\Component\HttpFoundation\Response::class)
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('an unmatched route returns a 404 Response (no exit)', function () {
    bootFixtureKernel();
    $response = \Ions\Foundation\Kernel::handle(\Ions\Support\Request::create('/nope'));
    expect($response->getStatusCode())->toBe(404);
});
```
> This requires the fixture to have a routes file + a controller dir the attribute/loader can read. Add minimal ones. Document the exact fixture additions in the task.

- [ ] **Step 2: Implement `handle()`** by refactoring `make()`: keep `captureRoute()`/matcher logic, but instead of instantiating-and-`send()`-ing inline, build the middleware stack for the group (`web`/`api` from `config('app.middleware', defaults)`), set the `ControllerDispatcher` (closed over the matched controller/method) as the terminal, run `Pipeline->handle($request)`, and RETURN the resulting `Response`. Map the routing exceptions (`ResourceNotFound`→404, `MethodNotAllowed`→405, `NoConfiguration`→404) to returned error `Response`s instead of `makeError()`-and-die. Add:
```php
public static function run(?Request $request = null): void
{
    static::sendResponse(static::handle($request ?? static::capture()));
}
```
Make `sendResponse(Response $r)` accept the response to send (currently sends `static::$response`). Keep `make(string $namespace = '')` as a thin BC shim that calls `static::run()` (preserving the `$namespace`/`api` segment behavior by threading it into `handle()`), so existing front controllers calling `Kernel::make()` keep working. The default middleware stacks include the 2.4 middleware so `SecurityHeaders` etc. apply uniformly (this also removes the need for the per-send `SecurityHeaders::apply()` added in Task 1.3 — leave that in as belt-and-suspenders or remove once the pipeline owns it; document the choice).

- [ ] **Step 3: Run → PASS.** Debug the fixture routing until the e2e tests pass. `composer qa` green.

- [ ] **Step 4: BC verification.** Confirm the existing security tests + boot tests still pass and that `Kernel::make()` is still callable with no args and with a namespace. Commit.
```bash
git commit -am "refactor(kernel): handle() returns a Response via middleware pipeline; make()/run() shims"
```

### Task 2.5.3: Move `ApiController` auth to the api middleware stack

- [ ] Now that `AuthMiddleware` exists and runs in the api pipeline, REMOVE the auth enforcement from `ApiController::__construct` (the `isAuthorized()` call + the `// TODO(Phase 2)` block). `ApiController` keeps reading inputs via `RequestInput` and exposes `auth_user_id` from `$request->attributes` (now set by the middleware). Verify the api stack in `config('app.middleware')` includes `AuthMiddleware`. Update/move the old constructor-auth expectations. Run the full suite + the new AuthMiddleware tests. Commit.

### Task 2.5.4: BC shims + deprecations

- [ ] Confirm `Kernel::app()/config()/session()/request()/response()` still work (they already read from static state / container). Add `@deprecated` docblocks to `Singleton`-based static service access where a container binding now exists, pointing to the container. Do NOT remove them. Add a short note to `UPGRADE-2.0.md` (or the plan's upgrade outline) describing the new `app.providers` / `app.middleware` config keys and their defaults. Commit.

---

## Sub-phase 2.6 — Remaining providers (incremental; may become its own plan)

> 2.5 was clean (no churn), so 2.6 proceeded incrementally. The clean, container-fit providers are DONE; the two entangled with later phases are deferred with reasons (below).

- [x] **AuthProvider** — DONE. Binds `jwt` (built via `Kernel::buildJwt()` from env/config); `AuthMiddleware` resolves it from the container (with a `buildJwt()` fallback when the provider isn't registered). Test: `AuthProviderTest` + the api-pipeline e2e still enforces auth via the container jwt. The `auth` (Sentinel `UserProvider`) binding is deferred to **Phase 5** (pluggable user provider) — out of scope for the container plumbing here.
- [x] **ConfigProvider** — DONE. Registers the built `Config` object as the container `config` instance; `config()` helper unchanged. Test: `ConfigProvider Test` asserts same instance.
- [x] **MailProvider** — DONE. Binds a singleton Symfony `Mailer` (DSN from `MAIL_*` env, credentials `rawurlencode`d); `newMailerDsn()` now delegates to `app('mailer')`. Test: `MailProviderTest`.
- [ ] **ViewProvider — DEFERRED to Phase 3 (view-layer modernization).** Rationale: the view environment is built per-request in `BaseController::_loadInit` and depends on the active locale (`Localization::init` + `tJson` globals), and the design is coupled to the **Smarty-4-vs-5 decision** (tracked debt). A correct ViewProvider needs the per-request/locale view factory designed alongside that decision. Building a half-version now (ignoring locale) would be lower quality than doing it with the Phase 3 view work. Tracked there.
- [ ] **RoutingProvider — DEFERRED (optional, low value now).** `Kernel::RouteCollection()` is static and `handle()` reads it directly; binding a `router`/`routes` service adds little until routing is reworked. Revisit during the Phase 3 routing consolidation (where `MRoute` is removed and `Route::middleware([...])` is added) if it reduces static coupling cleanly.

**Acceptance for Phase 2:** A fixture request flows Request → middleware pipeline → container-resolved controller → returned Response in `HandleTest`, with auth enforced by `AuthMiddleware` (not the constructor) and hardening headers applied by middleware; `Kernel::make()` still works for legacy front controllers; `composer qa` green; the Task 1.4 auth integration tests now exist (in `AuthMiddlewareTest`).

---

## Risks & mitigations

- **Big-bang risk in 2.5.** Mitigated by doing Container/providers/pipeline first behind passing tests, keeping `make()` as a shim, and gating 2.6 behind a "split if churn" check.
- **Static state vs container.** Kernel keeps static accessors as BC shims reading from the container; new code uses injection. Mark old paths `@deprecated`, don't delete (Phase 3+ removes).
- **Provider config replacement footgun.** Setting `app.providers` REPLACES defaults — document clearly; consider a `defaultProviders()` spread helper so apps append rather than replace.
- **Illuminate 9 container quirks** (e.g. `get()` auto-resolution). Pin behavior with the 2.1 tests; revisit at the Phase 4 Illuminate 11 upgrade.
- **CORS behavior change.** Re-enabling CORS via middleware changes response headers for API clients — document and make origins configurable (`config('app.cors')`).

## Self-review

- **Spec coverage vs master plan Phase 2 (items 1–7):** Container (2.1), ServiceProvider+providers (2.2, 2.6), Pipeline+MiddlewareInterface (2.3), security middleware (2.4), `Kernel::handle/run` + BC shims (2.5.2/2.5.4), controller container-resolution (2.5.1). Deferred Task-1.4 auth tests land in 2.4.2. ✓
- **TDD:** every task starts with a failing test except the pure-BC/shim/doc steps. ✓
- **No placeholders:** 2.1–2.4 have full code; 2.5–2.6 give concrete steps + sketches with explicit acceptance tests and a "split if churn" escape hatch (documented decomposition, not a gap). ✓
- **Type consistency:** `MiddlewareInterface::handle(Request, callable): Response`, `Pipeline(array, callable)`, `ServiceProvider(Container)` with `register()/boot()`, `AuthMiddleware(?Jwt)` — used consistently across tasks. ✓
