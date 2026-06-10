# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

For migration instructions see [UPGRADE-4.0.md](UPGRADE-4.0.md) (3.x → 4.0) and
[UPGRADE-3.0.md](UPGRADE-3.0.md) (2.1.x → 3.0).

## [Unreleased]

### Added

- **Zero-config provider auto-discovery** — when `app.providers` is not set, `Ions\Foundation\Discovery::providers()` registers the framework defaults plus providers discovered from the host `{src|app}/Providers/` directory (single glob per boot, `src/` → `app/` fallback preserved) and from installed composer packages declaring `extra.ions.providers` (read once per process from `vendor/composer/installed.json`, memoized). Host providers run last so they can override package/framework bindings. Escape hatches: an explicit `app.providers` list bypasses discovery entirely (BC); `app.discovery => false` keeps pure defaults; `app.dont_discover => ['vendor/package']` skips specific composer packages (exact name match). Test seam `Discovery::useMetadata()` / `Discovery::reset()` (wired into `Kernel::resetForTesting()`). See [docs/packages.md](docs/packages.md) and [docs/config.md](docs/config.md#appproviders). **Warning:** hosts that already register providers from `src/Providers/` manually (e.g. via `App\Booting`) will get them registered a second time under discovery — remove the manual registration, or set `app.providers` / `app.discovery => false`.
- **Provider discovery cache** — `ions discover:cache` freezes the discovered provider list into `var/cache/providers.php` (one `require` at boot, zero scans); `ions discover:clear` removes it; both are wired into `ions optimize` / `ions optimize:clear`. `APP_DEBUG` bypasses the cache like the route/config caches; stale cached FQCNs (provider deleted, package removed without re-running `discover:cache`) are filtered at load with a logged warning, never a fatal. The host-provider `require_once` fallback is hardened: top-level output is swallowed and any `Throwable` from a provider file logs a warning naming the file (`var/logs/app.log`) and the scan continues. See [docs/performance.md](docs/performance.md).
- **Worker-mode safety (EXPERIMENTAL)** — `Kernel::resetForRequest()` clears per-request state between sequential requests in one process (fresh `Request`/`Response`/legacy-session statics, `SessionManager::renew()` swaps in a brand-new inner session and re-points the shared `request_stack` so CSRF token storage follows, per-request Twig globals `_csrf_token`/`_trans`/`appUrl` re-evaluated via `ViewFactory::refreshRequestGlobals()`, query log flushed when enabled) while keeping boot state (config, container singletons, the route memo, the Twig Environment). `Ions\Runtime\WorkerRunner` (`@experimental`) drives a boot-once/handle-many loop over provider/emitter callables with optional `maxRequests` recycling. `Kernel::isBooted()`. See [docs/worker-mode.md](docs/worker-mode.md).

### Changed

- **`Kernel::handle()` syncs the shared request** — `Kernel::request()` now returns the request actually being handled instead of the boot-time capture (identical in classic FPM; essential for worker mode). See [UPGRADE-4.1.md](UPGRADE-4.1.md).

## [4.0.0] - 2026-06-10

The headline breaking change is the **PHP 8.3 minimum** (was 8.2); everything else
is additive. See [UPGRADE-4.0.md](UPGRADE-4.0.md).

### Added

- **Multi-driver filesystem** — `Ions\Filesystem\FilesystemManager` resolves named disks from `config('filesystem.disks')` (drivers `local`, `s3`, `ftp`, `sftp`, `memory`, plus `extend()` for custom drivers); bound as `filesystem.manager`. `Ions\Filesystem\Storage` static facade (`put/get/exists/delete/url`, `disk()`). Config keys `filesystem.default` / `filesystem.disks.*`.
- **Session** — `Ions\Session\SessionManager` over a Symfony `Session` (drivers `native` / `array` / `mock`); `Ions\Providers\SessionProvider` binds it as `session`; `session()` helper; `Ions\Http\Middleware\StartSessionMiddleware` auto-added to the web stack (before CSRF). Config `session.*`.
- **Console** — `Ions\Console\Kernel` (boots the container + discovers/registers commands), the `bin/ions` entry point (declared under `bin` in `composer.json`), command discovery from `config('console.commands')` + the host `app/Commands` directory, `make:command` generator, and `schedule:run`.
- **Cache** — `Ions\Providers\CacheProvider` binds the Illuminate `CacheManager` as `cache`; `cache()` helper (mirrors `config()`). Config `cache.*` (`default`, `prefix`, `persistent_store`, `stores`).
- **Events** — `Ions\Providers\EventProvider` binds the dispatcher as `events`; `event()` / `listen()` helpers; `Ions\Events\RequestHandled` (carries `Request` + `Response`) fired at the end of every request, fire-and-continue. Config `events.listen`.
- **Queue** — `Ions\Providers\QueueProvider` binds the `QueueManager` as `queue` (`sync` / `database` / `redis`); `Ions\Queue\Job` base class; `dispatch()` helper; `queue:work` command; `create_jobs_table` migration stub. Config `queue.*`.
- **HTTP auth controller** — `Ions\Auth\Http\AuthController` with `login` / `refresh` / `logout` / `forgotPassword` / `resetPassword` actions; access tokens bound to the authenticated user id (per-user JWT). `Ions\Auth\Contracts\SupportsPasswordReset` (`createResetCode()` / `resetPassword()`), implemented by `SentinelUserProvider`. `app.auth.public_paths` (segment-anchored) lets endpoints bypass `AuthMiddleware`; `throttle` on login.
- **`Ions\Http\Resource`** — abstract, `Responsable` API resource that shapes a single model/array/`stdClass` into a typed JSON payload; `make()`, `collection()`, configurable `data` wrapping (`withoutWrapping()`/`wrappedBy()`).
- **`Ions\Http\ResourceCollection`** — maps a collection/array/`LengthAwarePaginator` through a Resource class; paginator-aware (`meta` + `links`).
- **`Ions\Http\FormRequest`** — typed, self-validating request object with `rules()`, `authorize()` and `validated()`; `MyRequest::validate($request)` static helper.
- **`openapi:generate` command** (`OpenApiCommand`) — exports the routes (php/yaml + attribute routes) as an OpenAPI 3.0 spec with path params and bearer-auth security flags. Writes `--output` (default `openapi.json`) or `--stdout`.
- **`Ions\Media\Image`** — image processing (resize / scale / crop / cover / watermark / encode / save) over `intervention/image` v3, with `Ions\Media\ImageException`; restores the capability dropped with `verot/class.upload.php` in 3.0. `IonUpload` image hook. Config `media.driver` (`gd` | `imagick`).

### Changed

- **PHP minimum raised to 8.3** (was 8.2) — `composer.json` `require.php` `^8.3`, `config.platform.php` `8.3`. CI matrix runs PHP **8.3 and 8.4**. This floor bump is the reason 4.0.0 is a major.
- **Illuminate 11 → 12** — all `illuminate/*` constraints `^11` → `^12` (resolved v12.62). Carbon resolves to 3.x; Symfony stays 7.x; Monolog stays 3.x. No source changes required in Ions.
- **Cartalyst Sentinel v8 → v9** (`^8.0` → `^9.0`, resolved v9.0.0) — the Ions Sentinel adapter required no changes. Sentinel v9 itself requires PHP 8.3.
- **`ValidationException → 422` mapping** in `Ions\Http\ExceptionHandler` — Illuminate `ValidationException` renders as HTTP 422 with `{message, errors}` for API requests; a failed `authorize()` renders as 403.
- **CSRF unified onto the session store** — CSRF tokens are now stored in the bound `session` (via `SessionTokenStorage`), replacing the standalone `NativeSessionTokenStorage`; `csrfToken()` / `ionToken()` and `CsrfMiddleware` share one store.
- **Hardening** — `strict_types=1` enforced across Support/Bundles/Foundation; `src/` is PHP-8.4-clean; main PHPStan gate at **level 5**; core packages (Security, Container, Http, Auth, Providers, View, Filesystem, Session, Console, Media, Support) clean at **level 8**; PHPStan baseline burned down **74 → 25**.

### Removed

- *(none — 4.0 is additive; the removals were in 3.0.0, see below)*

### Security

- **Per-user JWT binding** — access tokens issued by `AuthController` are bound to the authenticated user's identifier, so `AuthMiddleware` resolves the real user rather than an application-supplied id.
- **`cache.persistent_store` documented as production-critical** — JWT revocations and rate-limit counters reuse the shared cache; the store must be a persistent driver (`file`/`redis`/`database`) in production, never `array` (which would silently disable revocation and throttling).
- **Login throttling** — the `AuthController::login` example route is rate-limited via the `throttle` middleware to slow credential-stuffing.

## [3.0.0] - 2026-06-10

### Added

- **PSR-11 service container** (`Ions\Container\Container` — extends Illuminate's container) with `bind`, `singleton`, `make`, and `has`/`bound` helpers.
- **`Ions\Container\ServiceProvider`** abstract base; two-pass bootstrap (all `register()` before any `boot()`).
- **Config-driven service providers** (`Ions\Providers\*`): `ConfigProvider`, `FilesystemProvider`, `DatabaseProvider`, `AuthProvider`, `MailProvider`, `ViewProvider`. Registered via `app.providers` config key; default set ships with the framework.
- **Middleware pipeline** (`Ions\Http\Middleware\Pipeline`) — pure PSR-style reducer chain.
- **`MiddlewareInterface`** (`handle(Request $request, callable $next): Response`) — the single contract all middleware must implement.
- **Default middleware stacks** for `web` and `api` groups (built by `Kernel::defaultMiddleware()`); overridable via `app.middleware`.
- **Built-in middleware**: `TrustedHostMiddleware`, `SecurityHeadersMiddleware`, `CorsMiddleware`, `CsrfMiddleware`, `AuthMiddleware`, `RateLimitMiddleware`, `ControllerDispatcher`.
- **`Ions\Http\Responsable`** interface — controllers may return any object implementing `toResponse(Request): Response`.
- **`Ions\Http\Json`** helpers: `Json::ok(mixed $data, int $status = 200): JsonResponse` and `Json::error(string $message, int $status = 400, array $extra = []): JsonResponse`.
- **`Ions\Http\ExceptionHandler`** — unified `render(Throwable, Request): Response`; returns JSON for API routes and HTML for web; leaks no internals in production (`APP_DEBUG=false`).
- **`Ions\Auth\Contracts\UserProvider`** interface (`retrieveById`, `retrieveByCredentials`, `validateCredentials`) and **`Authenticatable`** interface (`getAuthIdentifier`, `getAuthIdentifierName`).
- **`SentinelUserProvider`** (default) and **`EloquentUserProvider`** adapters; selectable via `auth.provider` config key; custom FQCN also accepted.
- **JWT refresh tokens** (`Jwt::issueRefresh(string $userId): string`) with configurable TTL (`app.jwt.refresh_ttl`, default 14 days); `typ` claim enforces strict token-type separation.
- **JWT revocation** (`Jwt::revoke(string $token): void`) backed by `CacheRevocationStore` (file cache, persistent) or a custom `RevocationStore` implementation; in-memory `ArrayRevocationStore` for tests.
- **`Jwt::refresh(string $refreshToken): string`** — exchanges a valid refresh token for a new access token with automatic rotation (used refresh token is revoked).
- **Clock leeway** (`app.jwt.leeway` seconds, default 0) injected into `Jwt` constructor (`clockLeewaySeconds` parameter) to tolerate NTP drift between nodes.
- **`RateLimitMiddleware`** — sliding-window rate limiting by IP + path; returns 429 with `Retry-After` header; configurable via `app.ratelimit.max` / `app.ratelimit.decay`; accessible via the `throttle` middleware alias.
- **`SecurityHeadersMiddleware`** and **`Ions\Security\SecurityHeaders::apply()`** — sets `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `X-XSS-Protection`; applies `Content-Security-Policy` from `app.security.csp` when the header is not already present.
- **CSRF enforcement** (`CsrfMiddleware`) in the default web stack; token field `_ion_token` or header `X-CSRF-TOKEN`; returns 419 on mismatch; `ionToken()` / `csrfToken()` Twig helpers; disabled via `app.csrf.enabled = false`.
- **`Ions\Security\UploadValidator`** — extension allow-list with hard-coded executable-extension deny-list; used by `IonUpload` and `IonDisk` to block RCE vectors.
- **Trusted-host enforcement** via `TrustedHostMiddleware` (Symfony `setTrustedHosts()`); configured via `app.trusted_hosts` (regex patterns without delimiters).
- **`make:middleware`** and **`make:service-provider`** Artisan-style generators (under `src/commands/`).
- **MySQL CI job** in GitHub Actions (`php 8.2`, MySQL 8, `IONS_TEST_MYSQL=1`); runs alongside the existing SQLite matrix (PHP 8.2 + 8.3).
- **PHPStan level-4** gate on the full codebase; **level-8** gate (`phpstan-core.neon`) on the core packages; `strict_types=1` on all new files.

### Changed

- **`Kernel::boot(?string $basePath = null): void`** — accepts optional absolute base path for test isolation; runs provider two-pass bootstrap internally.
- **`Kernel::handle(Request $request, string $namespace = ''): SymfonyResponse`** — new primary entry point; runs the middleware pipeline and returns a `Response` (never exits); all exceptions handled via `ExceptionHandler`.
- **`Kernel::run(?Request $request = null, string $namespace = ''): void`** — convenience entry point that calls `handle()` then `sendResponse()`; replaces `make()` in new front controllers.
- **`Kernel::make(string $namespace = ''): void`** — retained as a BC shim; delegates to `run()`.
- **Controllers may return a `Response`** (or any `Responsable`) directly; `ControllerDispatcher` normalises the return value. Previously all controllers wrote to the shared response object and the kernel always sent it.
- **Routing consolidated to `Ions\Bundles\Route`** — single fluent API: `get/post/put/patch/delete/options/any/match/resource`; `prefix(...)->group(...)` for nesting; `->middleware([...])` for per-route middleware; `MRoute` facade removed. Attribute routing (`#[Route]`) supported in `src/Http/` (web) and `app/Api/` (api) directories.
- **Illuminate 9 → 11** (incremental via 10); **Symfony 6 → 7**; **Monolog 2 → 3**; **Pest 2 → 3 / PHPUnit 10 → 11**. Cartalyst Sentinel bumped to `^8.0`.
- **Twig is the sole view engine** (`Ions\View\ViewFactory` returns a fully configured `Twig\Environment`; bound as `view` in the container).
- **`QueryBuilder::allowFilters()`** accepts only an `array` argument (variadic / string form removed); enforces strict column allow-list by default.
- **`ApiController` response helpers** (`display()`, `returnStructure()`, etc.) now `return` a `Response` instead of echoing and exiting; all call sites must add `return`.

### Removed

- **RedBean** (`gabordemooij/redbean`) — database layer is Illuminate Eloquent only.
- **Smarty** (`smarty/smarty`) — Twig is the sole view engine.
- **`verot/class.upload.php`** — replaced by `Ions\Security\UploadValidator`.
- **`MRoute` facade** — use `Ions\Bundles\Route` directly.
- **Broken RSA-as-HMAC JWT** — the old implementation used an RSA public key as an HMAC secret, issued non-expiring tokens, and had no user binding. Replaced by `lcobucci/jwt` 5 with HMAC-SHA256, expiry, `sub` claim, and revocation.
- **Spoofable host check** (`Host == APP_URL`) — replaced by `TrustedHostMiddleware` / `setTrustedHosts()`.
- **`spatie/ignition` and `filp/whoops` from `require`** — were unused at runtime after `ExceptionHandler` was introduced in Phase 3; removed from production dependencies.

### Security

- **JWT fully rebuilt** (`Ions\Security\Jwt`) — HMAC-SHA256 signing via `lcobucci/jwt` 5; mandatory `APP_KEY` ≥ 32 bytes; short-lived access tokens + long-lived refresh tokens; `jti` revocation deny-list; `typ` claim prevents cross-type token misuse; clock leeway for NTP drift tolerance. All pre-3.0 tokens are invalid after upgrading.
- **Upload RCE vector closed** — `UploadValidator` enforces an extension allow-list and a hard-coded deny-list that includes all PHP-executable, script, and binary extensions; used by both `IonUpload` and `IonDisk`.
- **Trusted-host enforcement** — `TrustedHostMiddleware` replaces the previous `Host == APP_URL` comparison which was spoofable via `X-Forwarded-Host`.
- **CSRF enforced by default** — `CsrfMiddleware` is in the default web stack; all state-changing requests (`POST`, `PUT`, `PATCH`, `DELETE`) require a valid `_ion_token` field or `X-CSRF-TOKEN` header (HTTP 419 otherwise).
- **Query-filter allow-listing** — `QueryBuilder::allowFilters()` now enforces a strict allow-list; unrecognised filter columns throw `InvalidFilterQuery`; passing a non-array argument throws `TypeError` (fail-closed).

[Unreleased]: https://github.com/tahadeveloper/ions.core/compare/4.0.0...HEAD
[4.0.0]: https://github.com/tahadeveloper/ions.core/compare/3.0.0...4.0.0
[3.0.0]: https://github.com/tahadeveloper/ions.core/releases/tag/3.0.0
