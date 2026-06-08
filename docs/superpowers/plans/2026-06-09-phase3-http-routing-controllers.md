# Phase 3 — HTTP / Routing / Controllers Modernization (Implementation Plan)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make controllers first-class HTTP citizens: they **return** a `Response` (instead of `echo`/`exit`), routing is consolidated on `Bundles\Route` (+ attributes) with per-route/group middleware, and *all* error paths flow through a single exception handler that returns a `Response` (no `die()` in the request lifecycle). Plus finish the two providers deferred from Phase 2.6 (View, optional Routing) and clear the `#[NoReturn]`→`never` debt.

**Architecture:** Builds directly on Phase 2's pipeline. `ControllerDispatcher` (the pipeline terminal) starts honoring the action's **return value** (a `Response` or `Responsable`), falling back to the shared `Kernel::response()` only for legacy void actions — so old echo-style controllers keep working while new ones `return`. `Kernel::handle()` wraps route matching + the pipeline in one `try` that routes every `Throwable` (Symfony `HttpException` from `abort()`, routing exceptions, and unexpected errors) through a single `ExceptionHandler` service producing a `Response` (JSON for api/`wantsJson`, Ignition/HTML for web). `MRoute` is deleted; `Route` gains `middleware()`. A locale-aware `ViewProvider` moves Twig/Smarty construction out of `BaseController::_loadInit` into the container.

**Tech Stack:** unchanged from Phase 2 — PHP 8.2+, Symfony HttpFoundation/Routing, Illuminate 9 (container/http), Twig 3 / Smarty 4, Spatie Ignition + Whoops, Pest 2 + PHPStan 2 (level 4 + baseline) + PHP-CS-Fixer. No new runtime deps expected (confirm `symfony/http-kernel` is available for `HttpException` — it's already pulled via `abort()`'s `Symfony\Component\HttpKernel\Exception\HttpException`).

**Branch:** fresh branch off `main` (e.g. `phase3-http`). `main` currently holds Phases 0–2.

---

## How to read this plan

Six sequenced sub-phases, each shippable and reviewable. Guardrails for **every** task (same as Phase 2): keep `composer qa` green (cs + stan + the current **87 tests** must never regress); new code must be PHPStan-clean (no new baseline suppressions — call private statics via `self::`; only the legacy baseline may shrink); preserve the `Ions\` public surface via BC shims and document any break in the v2 upgrade guide.

- **3.1 Routing consolidation** and **3.4 `never`-types cleanup** — low-risk, **fully specified with TDD + code**.
- **3.2 Controllers return Response** and **3.3 Centralized exception handling** — the core integration tasks; detailed steps + code sketches + explicit acceptance tests, with latitude on exact wiring and a "split if churn" hedge.
- **3.5 ViewProvider** — needs a product decision first (Twig-only vs keep Smarty — see Decision D-V below); spec assumes "keep both, make locale-aware".
- **3.6 RoutingProvider** — optional; do only if it cleanly reduces static coupling.

### Decision D-V — view engine: **RESOLVED → (b) Twig-only**
**Decision (user, 2026-06-09): drop Smarty; Twig is the sole view engine.** So sub-phase 3.5 additionally: removes the `smarty/smarty` dependency from `composer.json`; removes the `Smarty` branch from `BaseController::_loadInit`; removes/retires `src/Traits/Smarty.php`; and the `ViewProvider`/`ViewFactory` builds **only** a Twig `Environment`. This **resolves the Smarty-5 tracked debt** (no migration needed — Smarty is gone). Breaking change for any app using Smarty templates → document in the v2 upgrade guide (Smarty removed; port templates to Twig). The `config('app.templates')` key becomes effectively `['twig']`; treat a `smarty` entry as a no-op (or a logged deprecation).

---

## Current-state facts this plan builds on (verified against `main`)

- `Kernel::handle(Request, namespace): SymfonyResponse` already exists: it matches the route, builds a closure-or-`ControllerDispatcher` terminal, runs the per-group middleware `Pipeline`, and returns the Response. Routing exceptions (`NoConfiguration`/`MethodNotAllowed`/`ResourceNotFound`) are caught and turned into `errorResponse()` Responses. **It does NOT catch `HttpException` (from `abort()`) or generic `Throwable` thrown inside the pipeline/controller** — those currently escape to the globally-registered Ignition/Whoops handler (registered at the top of `handle()` via `errorDebug()`/`errorDebugApi()`).
- `ControllerDispatcher::__invoke()` runs the lifecycle hooks and the action, then **returns `Kernel::response()`** (the shared response) — the action's own return value is **ignored**. `callAction()`/the action are `void`.
- `ApiController::display()` and `returnStructure()` build content on the shared response, `SecurityHeaders::apply()`, `send()`, then **`exit()`** — they bypass the pipeline's return path.
- `abort($code,$msg)` (helper) throws `Symfony\Component\HttpKernel\Exception\HttpException`.
- `MRoute` is referenced only vestigially: `Kernel::captureRoute()` does `MRoute::$collection = new RouteCollection();` then uses `Kernel::RouteCollection()` (the `Route` facade's collection); `RouteListCommand` has the same dead pattern. The real routing is `Bundles\Route` + attribute routing.
- `#[NoReturn]` (`JetBrains\PhpStorm\NoReturn`, undeclared IDE stub) is used in `ApiController` (`unauthorizedResponse`/`returnStructure`/`notFoundResponse`/`display`) and `helpers.php`.

---

## File structure

**New:**
- `src/Http/Responsable.php` — interface `toResponse(Request): Response`.
- `src/Http/Response/Json.php` (or extend existing `Ions\Support\JsonResponse`) — typed JSON response helpers (`Json::ok($data)`, `Json::error($msg,$code)`).
- `src/Http/ExceptionHandler.php` — single handler: `Throwable → Response` (api JSON / web HTML+Ignition).
- `src/Providers/ViewProvider.php` (3.5); optionally `src/Providers/RoutingProvider.php` (3.6).
- tests under `tests/Unit/Http/**`, `tests/Feature/**`.

**Modified:**
- `src/Http/Middleware/ControllerDispatcher.php` — honor action return value.
- `src/Foundation/{Api,Base}Controller.php` — `callAction` returns the action result; `display()`/`returnStructure()`/`unauthorizedResponse()`/`notFoundResponse()` return `Response` (deprecate the exit-style), `#[NoReturn]`→`never` where still exit-based.
- `src/Foundation/Kernel.php` — single exception-handling `try`; drop `MRoute`; `captureRoute()` cleanup; route per-request middleware merge (3.1).
- `src/Bundles/Route.php` — `middleware()` support.
- **Delete:** `src/Bundles/MRoute.php`.
- `src/commands/RouteListCommand.php` — drop MRoute reference.
- `src/helpers.php` — `render()`/`display()` remain as shims; `abort()` unchanged.

---

## Sub-phase 3.1 — Routing consolidation (remove MRoute, add Route middleware)

### Task 3.1.1: Delete `MRoute` and its vestigial references

**Files:** delete `src/Bundles/MRoute.php`; modify `src/Foundation/Kernel.php`, `src/commands/RouteListCommand.php`; test `tests/Feature/RouteCollectionTest.php`.

- [ ] **Step 1: Characterization test (routing still works without MRoute).**
```php
<?php

test('web routes still load and match after MRoute removal', function () {
    bootFixtureKernel();
    $response = \Ions\Foundation\Kernel::handle(\Ions\Support\Request::create('/ping'));
    expect($response->getStatusCode())->toBe(200)->and($response->getContent())->toBe('pong');
});
```
Run → PASS today (baseline). Keep it; it must still pass after removal.

- [ ] **Step 2: Remove the vestigial `MRoute::$collection = new RouteCollection();` line** in `Kernel::captureRoute()` (line ~619) and the commented `//$routes = MRoute::$collection;` (line ~623). The real source is `Kernel::RouteCollection()` for php routes — unchanged. Remove `use Ions\Bundles\MRoute;` from Kernel.

- [ ] **Step 3: Remove the MRoute reference in `RouteListCommand`** (the `MRoute::$collection = new RouteCollection();` at line ~77 and the `use`); it already uses `Kernel::RouteCollection()`.

- [ ] **Step 4: Delete `src/Bundles/MRoute.php`.** Grep `MRoute` across `src/` + `tests/` → must be zero hits.

- [ ] **Step 5: Run + commit.**
```bash
vendor/bin/pest && composer qa
git rm src/Bundles/MRoute.php
git commit -am "refactor(routing): remove vestigial MRoute; Route is the single fluent router"
```
Expected: 88 tests (the +1 characterization test), qa green. Document the `MRoute` removal in the v2 upgrade guide (already listed there).

### Task 3.1.2: `Route::middleware()` — per-route / per-group middleware

**Files:** `src/Bundles/Route.php`, `tests/Unit/Bundles/RouteMiddlewareTest.php`, `src/Foundation/Kernel.php` (merge route middleware into the stack).

**Design:** `Route::get(...)->middleware(['auth'])` (and within `prefix(...)->group(...)`) records middleware *names/classes* in the Symfony `Route`'s `options['middleware']`. `Kernel::handle()`, after matching, reads `$matcherParams['_middleware'] ?? []` (Symfony copies route options into defaults? No — read from the matched `Route` options) and **appends** the route's middleware to the group stack before building the `Pipeline`.

- [ ] **Step 1: Failing test** — registering a route with middleware records it and it is retrievable from the collection.
```php
<?php

use Ions\Bundles\Route;
use Ions\Foundation\Kernel;

test('Route::middleware records middleware on the route options', function () {
    bootFixtureKernel();
    Route::get('/guarded', fn () => new \Symfony\Component\HttpFoundation\Response('x'))->middleware(['my-mw']);
    $route = Kernel::RouteCollection()->get(/* the generated name */ ...); // adjust: fetch by iterating
    // find the route whose path is /guarded
    $found = null;
    foreach (Kernel::RouteCollection()->all() as $r) { if ($r->getPath() === '/guarded') { $found = $r; } }
    expect($found)->not->toBeNull()
        ->and($found->getOption('middleware'))->toBe(['my-mw']);
});
```

- [ ] **Step 2: Implement `middleware()` on `Route`.** `Bundles\Route` is the fluent builder; after `newRoute()` creates the Symfony `Route` and adds it to the collection, the instance must keep a reference to that `Route` so `->middleware([...])` can call `$route->setOption('middleware', $names)` and return `$this`. (Refactor `newRoute()` to store `$this->lastRoute = $sroute;`.) Support merging with group-level middleware set via `prefix($p, $controls, $closure)` — extend prefix/group to push a middleware array onto a stack that `inRoute()` seeds into each route's options. Keep BC: routes without `middleware()` get `[]`.

- [ ] **Step 3: Resolve middleware names → instances.** Add `config('app.middleware_aliases', [...])` mapping short names (e.g. `'auth' => AuthMiddleware::class`) to classes, plus support fully-qualified class names directly. In `Kernel::handle()`, after matching a class/closure route, read the matched route's `options['middleware']`, resolve each via the container (`make()`), and append to the group `$stack` before `new Pipeline($stack, $terminal)`. (Getting the matched `Route` object: `$routes->get($matcherParams['_route'])` — Symfony puts the matched route name in `_route`.)

- [ ] **Step 4: Integration test** — a route with `->middleware([SomeMw::class])` runs that middleware (e.g. a middleware that sets a header) in addition to the group stack. Assert the header is present on the response through `Kernel::handle()`.

- [ ] **Step 5: Run + commit.** `composer qa` green. Commit `feat(routing): per-route and per-group middleware via Route::middleware()`.

---

## Sub-phase 3.2 — Controllers return a Response

> Core integration task. Non-negotiables: old void/echo controllers keep working (BC); new controllers may `return` a `Response`/`Responsable`; `composer qa` green.

### Task 3.2.1: `Responsable` interface + typed JSON helpers

**Files:** `src/Http/Responsable.php`, `src/Http/Response/Json.php`, tests.

- [ ] **Step 1: Failing tests** for a `Responsable` and `Json` helpers:
```php
<?php
use Ions\Http\Json;
use Symfony\Component\HttpFoundation\JsonResponse;

test('Json::ok wraps data in a 200 JSON response', function () {
    $r = Json::ok(['a' => 1]);
    expect($r)->toBeInstanceOf(JsonResponse::class)
        ->and($r->getStatusCode())->toBe(200)
        ->and(json_decode($r->getContent(), true))->toBe(['status' => 'success', 'data' => ['a' => 1]]);
});

test('Json::error builds an error envelope with the given status', function () {
    $r = Json::error('nope', 422);
    expect($r->getStatusCode())->toBe(422)
        ->and(json_decode($r->getContent(), true))->toMatchArray(['status' => 'error', 'message' => 'nope']);
});
```

- [ ] **Step 2: Implement** `Ions\Http\Responsable` (`toResponse(Request $request): Response`) and `Ions\Http\Json` (static `ok(mixed $data, int $status=200)`, `error(string $message, int $status=400, array $extra=[])` returning Symfony `JsonResponse` with the project's envelope — match the existing `{status, message, code}` / `{status_code, success, error, data}` shapes used in `ApiController`/`Kernel::errorResponse` so it's consistent; pick ONE envelope and document it). Make tests pass.

- [ ] **Step 3: Commit** `feat(http): Responsable interface + Json response helpers`.

### Task 3.2.2: `ControllerDispatcher` honors the action return value

**Files:** `src/Http/Middleware/ControllerDispatcher.php`, `src/Foundation/{Api,Base}Controller.php` (`callAction` returns the result), tests.

- [ ] **Step 1: Failing test** — a controller action that RETURNS a Response is used as the response; a void action still falls back to `Kernel::response()`.
```php
<?php
use Ions\Foundation\Kernel;
use Ions\Http\Middleware\ControllerDispatcher;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

class ReturningController { public function show(Request $r): Response { return new Response('returned', 201); } }
class VoidController { public function show(Request $r): void { Kernel::response()->setContent('void-written'); } }

beforeEach(fn () => bootFixtureKernel());

test('dispatcher uses a Response returned by the action', function () {
    $res = (new ControllerDispatcher(Kernel::app(), ReturningController::class, 'show'))(Request::create('/'));
    expect($res->getStatusCode())->toBe(201)->and($res->getContent())->toBe('returned');
});

test('dispatcher falls back to the shared response for void actions (BC)', function () {
    $res = (new ControllerDispatcher(Kernel::app(), VoidController::class, 'show'))(Request::create('/'));
    expect($res->getContent())->toBe('void-written');
});
```

- [ ] **Step 2: Implement.** In `ControllerDispatcher::__invoke()`, capture the action's return value: change the `callAction`/direct-method calls to `$result = method_exists(...) ? $instance->callAction(...) : ...`. Then `return self::normalize($result, $request)` where normalize: a `Response` → itself; a `Responsable` → `$result->toResponse($request)`; otherwise (`null`/void) → `Kernel::response()`. Update `BaseController::callAction()` and `ApiController::callAction()` to **return** `$this->{$method}(...)` (was `void` → change return type to `mixed`). This is BC: existing void actions return null → fallback path. Make both tests pass.

- [ ] **Step 3: Commit** `feat(http): controllers may return a Response/Responsable; void actions still supported`.

### Task 3.2.3: `ApiController` response helpers return Responses (stop exiting)

**Files:** `src/Foundation/ApiController.php`, tests.

- [ ] Convert `display()`/`returnStructure()`/`unauthorizedResponse()`/`notFoundResponse()` so they **return** a `Response` (built via `Ions\Http\Json`) instead of `send()`+`exit()`. Keep the old exit-behavior available only behind a clearly-deprecated path if any existing controller relies on it — but prefer: actions `return $this->display(...)`. Add a test that `display('{"a":1}')` returns a `JsonResponse` (not exits) with the JSON body + `application/json`. Remove `#[NoReturn]` from these (they now return). This closes the Phase-1-tracked `display()` story (headers now applied by the pipeline's `SecurityHeadersMiddleware` since the Response flows back through it).
- [ ] **Migration note:** controllers that currently call `$this->display($json)` (which exited) must now `return $this->display($json)`. Document in the upgrade guide. Keep a `displayAndExit()` deprecated shim ONLY if needed for an app that can't migrate immediately — otherwise omit (YAGNI).
- [ ] Commit `refactor(api): response helpers return Responses instead of exit()`.

---

## Sub-phase 3.3 — Centralized exception handling

> Core integration task. Replaces the scattered `errorDebug`/`errorDebugApi` global-handler registration + `errorResponse()` with ONE handler that converts any `Throwable` to a `Response`.

### Task 3.3.1: `ExceptionHandler` service

**Files:** `src/Http/ExceptionHandler.php`, `tests/Unit/Http/ExceptionHandlerTest.php`.

- [ ] **Step 1: Failing tests:**
```php
<?php
use Ions\Http\ExceptionHandler;
use Ions\Support\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(fn () => bootFixtureKernel());

test('renders an HttpException with its status (api → JSON)', function () {
    $req = Request::create('/api/x'); // api
    $res = (new ExceptionHandler())->render(new HttpException(403, 'forbidden'), $req);
    expect($res->getStatusCode())->toBe(403)
        ->and(json_decode($res->getContent(), true))->toMatchArray(['status' => 'error', 'message' => 'forbidden']);
});

test('renders a generic Throwable as 500', function () {
    $req = Request::create('/page');
    $res = (new ExceptionHandler())->render(new \RuntimeException('boom'), $req);
    expect($res->getStatusCode())->toBe(500);
});

test('web request gets an HTML response', function () {
    $req = Request::create('/page');
    $res = (new ExceptionHandler())->render(new HttpException(404, 'nope'), $req);
    expect($res->headers->get('Content-Type'))->toContain('text/html')
        ->and($res->getStatusCode())->toBe(404);
});
```

- [ ] **Step 2: Implement** `ExceptionHandler::render(Throwable $e, Request $request): Response`:
   - Status = `$e instanceof HttpException ? $e->getStatusCode() : 500`.
   - api/`wantsJson` → `Json::error($e->getMessage() or generic, $status)`; in debug, include exception class/trace.
   - web → HTML: in debug, render via Ignition's HTML (or Whoops); in prod, the templated `error.html.php` (reuse the logic currently in `Kernel::HtmlErrorRender`/`errorResponse`).
   - Never leak stack traces when `APP_DEBUG` is off.
   Make tests pass.

- [ ] **Step 3: Commit** `feat(http): single ExceptionHandler (Throwable → Response)`.

### Task 3.3.2: Wire `ExceptionHandler` into `Kernel::handle()`; retire the duplicated debug registration

**Files:** `src/Foundation/Kernel.php`, tests.

- [x] Wrap the route-match + pipeline in `handle()` in a single `try { ... } catch (Throwable $e) { return $handler->render($e, $request); }`. Routing-exception catches translated to `NotFoundHttpException`/`MethodNotAllowedHttpException` before delegating to the handler. Removed `errorDebug()`/`errorDebugApi()` per-request registration and `errorResponse()` / `HtmlErrorRender()`.
- [x] **Integration test:** a fixture route whose action calls `abort(403)` → `Kernel::handle()` returns a 403 Response — proving `abort()` (HttpException) is now caught and rendered, not fatal.
- [x] **Integration test:** a fixture route whose action throws a generic `\RuntimeException` → 500 Response (no leaked trace when `APP_DEBUG` off).
- [x] Confirmed all existing tests still pass (BootErrorTest etc. unaffected — boot-time, separate from request handling). Committed `refactor(kernel): funnel all request Throwables through ExceptionHandler; drop errorDebug/errorDebugApi/errorResponse`.

> **DX trade-off (tracked future enhancement):** Removing `errorDebug`/`errorDebugApi` drops the global Spatie Ignition / Whoops pretty-error registration that previously decorated PHP-level errors outside the request cycle. `ExceptionHandler` now renders errors (simple HTML in debug; no-leak prod behavior). Richer Ignition rendering inside `ExceptionHandler::html()` for the debug path is a tracked future enhancement (wire `Ignition::make()->renderException($e)` in a follow-up once the ExceptionHandler test surface is broader).

---

## Sub-phase 3.4 — `#[NoReturn]` → `never` cleanup (tracked debt)

**Files:** `src/Foundation/ApiController.php`, `src/helpers.php`, `phpstan-baseline.neon`.

- [ ] After 3.2.3, the `ApiController` methods that used `#[NoReturn]` now RETURN Responses, so the attribute is simply removed there. For any remaining genuinely-non-returning functions (e.g. helpers that `exit()` like `display()` in `helpers.php`), replace `#[NoReturn]` with the PHP 8.1 `never` return type (real, enforced, dependency-free). Remove the `use JetBrains\PhpStorm\NoReturn;` imports once unused.
- [ ] Regenerate `phpstan-baseline.neon` — the `Attribute class JetBrains\PhpStorm\NoReturn does not exist` entries should DISAPPEAR (removals only; no new suppressions). Run `vendor/bin/pest` + `composer qa`. Commit `chore: replace #[NoReturn] with native never return types; drop IDE-stub baseline entries`.

---

## Sub-phase 3.5 — ViewProvider (deferred from 2.6) — **D-V resolved: Twig-only**

> Per Decision D-V: **Twig-only**. This sub-phase builds a locale-aware Twig `ViewFactory` in the container AND removes Smarty (dependency, trait, BaseController branch). Resolves the Smarty-5 tracked debt. Add a task to: `composer remove smarty/smarty`; delete `src/Traits/Smarty.php` and its `use Smarty;` in `BaseController`; strip the `smarty` branch from `_loadInit`; treat a `smarty` value in `config('app.templates')` as a no-op. Document the Smarty removal in the upgrade guide.

**Files:** `src/Providers/ViewProvider.php`, `src/Foundation/BaseController.php`, `src/Traits/{Twig,Smarty}.php` (maybe), tests.

- [ ] **Step 1:** Define a `view` container service — a small `Ions\View\ViewFactory` that, given a locale + folder, returns a configured Twig `Environment` (and/or Smarty) with the `tJson` global applied. Move the construction logic currently inline in `BaseController::_loadInit` (and the `Twig`/`Smarty` traits' `TwigInit`/`smartyInit`) into `ViewProvider`/`ViewFactory`.
- [ ] **Step 2:** `BaseController::_loadInit` resolves `view` from the container and configures it for the request locale, instead of `new`-ing. Preserve: `_super` locale override, `Localization::init`, `tJson` global, `config('app.templates')` selection.
- [ ] **Step 3:** Test: the container resolves a `view`/Twig environment with the `tJson` global set for a given locale; `BaseController` rendering still works (a fixture web controller rendering a tiny Twig template returns expected HTML). Add `ViewProvider` to `defaultProviders()`.
- [ ] Commit `feat(providers): locale-aware ViewProvider; BaseController resolves views from the container`.

---

## Sub-phase 3.6 — RoutingProvider (optional) — **SKIPPED (no clean benefit now)**

- [x] **Decision (2026-06-09): SKIPPED.** Rationale: the `Bundles\Route` fluent facade writes routes directly into the static `Kernel::RouteCollection()`, and `Kernel::captureRoute()` rebuilds the collection per request (php/yaml + attribute routes). Binding a `routes`/`router` container service would not change that source-of-truth without first reworking the `Route` facade itself — i.e. it adds a binding nothing meaningfully consumes. Per the "skip if it doesn't reduce coupling without churn" guidance, `Kernel::RouteCollection()` remains the route source. **Revisit** when/if routing is further reworked (e.g. an instance-based router replacing the static `Route` facade) — at which point a `RoutingProvider` becomes worthwhile.

---

## Acceptance for Phase 3
- `MRoute` gone; `Route` is the single fluent router with `middleware()` support; per-route middleware runs in the pipeline.
- Controllers can `return` a `Response`/`Responsable`; void/echo controllers still work; `ApiController` helpers return Responses (no `exit()` in the request path).
- Every `Throwable` in request handling (incl. `abort()`/`HttpException`) is rendered by a single `ExceptionHandler` into a `Response` (JSON api / HTML web; no leaked traces in prod); no `die()` in the request lifecycle.
- `#[NoReturn]` replaced by `never`; the IDE-stub baseline entries are gone.
- `ViewProvider` (per D-V) registered; `RoutingProvider` done or explicitly skipped.
- `composer qa` green; test count up materially; no new baseline suppressions.

---

## Risks & mitigations
- **Controllers-return-Response BC.** The fallback-to-`Kernel::response()` for void/null actions keeps every existing controller working; only the `ApiController::display()`-then-`exit()` callers must switch to `return $this->display(...)` — documented as a v2 break.
- **Exception-handler regressions.** Build `ExceptionHandler` standalone with tests (3.3.1) before wiring it into `handle()` (3.3.2); keep Ignition/Whoops for debug rendering inside it so the DX doesn't regress.
- **Route middleware resolution surface.** Keep an alias map + accept FQCN; resolve via the container so middleware can have deps. If it gets complex, ship route-level FQCN middleware first and add aliases later.
- **View refactor entanglement (D-V).** Gated on the product decision; default (a) is lowest-disruption. Don't start 3.5 until D-V is answered.
- **Scope.** 3.1/3.4 are quick wins; 3.2/3.3 are the heart; 3.5/3.6 may split into their own plan if 3.2/3.3 churn. Each sub-phase ships independently and `main` stays releasable.

## Self-review
- **Coverage vs master-plan Phase 3 items:** routing consolidation + `MRoute` removal + `Route::middleware` → 3.1; ControllerDispatcher/container-resolution → already done in Phase 2 (noted); typed responses + return-Response + `display()` gap → 3.2; `exit()`/`die()` removal + central exception handling → 3.2.3 + 3.3; Smarty-5 decision → D-V/3.5; `#[NoReturn]`→`never` + `IonDisk::download` bug → 3.4 (note: `IonDisk::download()` inverted-semantics bug belongs to the storage layer; fix it here only if touched, else carry to the DB/storage phase — flagged); ViewProvider/RoutingProvider → 3.5/3.6. All covered or explicitly carried.
- **TDD:** every behavioral task starts with a failing test; cleanup/doc steps excepted.
- **No placeholders:** 3.1/3.4 fully coded; 3.2/3.3 have concrete code sketches + acceptance tests; 3.5/3.6 are task-level with a decision gate and a split/skip hedge — documented decomposition, not gaps.
- **Type consistency:** `Responsable::toResponse(Request): Response`, `Json::ok/error(): JsonResponse`, `ControllerDispatcher` return-normalization, `ExceptionHandler::render(Throwable, Request): Response` — consistent across tasks.
