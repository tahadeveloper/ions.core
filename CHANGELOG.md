# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

For migration instructions see [UPGRADE-3.0.md](UPGRADE-3.0.md).

## [Unreleased]

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

[Unreleased]: https://github.com/tahadeveloper/ions.core/compare/3.0.0...HEAD
[3.0.0]: https://github.com/tahadeveloper/ions.core/releases/tag/3.0.0
