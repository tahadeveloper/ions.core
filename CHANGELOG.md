# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

For migration instructions see [UPGRADE-4.5.md](UPGRADE-4.5.md) (4.4 → 4.5),
[UPGRADE-4.4.md](UPGRADE-4.4.md) (4.3 → 4.4),
[UPGRADE-4.3.md](UPGRADE-4.3.md) (4.2 → 4.3),
[UPGRADE-4.2.md](UPGRADE-4.2.md) (4.1 → 4.2),
[UPGRADE-4.1.md](UPGRADE-4.1.md) (4.0 → 4.1),
[UPGRADE-4.0.md](UPGRADE-4.0.md) (3.x → 4.0) and
[UPGRADE-3.0.md](UPGRADE-3.0.md) (2.1.x → 3.0).

## [Unreleased]

## [4.7.0] - 2026-06-13

### Added
- **`db:seed` runner + composable `Ions\Database\Seeder`.** The framework could scaffold seeders (`make:seeder`) but had no command to run them — every host had to write its own. New `db:seed {--class= : defaults to DatabaseSeeder} {--database=}` resolves a seeder by FQCN, by the layout namespace (`Database\Seeders\X` on the host-root layout, `App\Database\Seeders\X` legacy), or by requiring the file directly when the host hasn't dumped autoload — then runs its `seed()` (or `run()`, Laravel-style). A new base `Ions\Database\Seeder` adds `call([A::class, B::class])` for composing seeders (a `DatabaseSeeder` orchestrating others); the `make:seeder` stub now extends it and the skeleton ships a `database/seeders/DatabaseSeeder.php`. Plain seeders — a class with `seed()` and no base (the previous shape) — still run unchanged (BC).

### Fixed
- **`migrate` auto-creates the migrations table on first run.** `php bin/ions migrate` on a fresh database threw `SQLSTATE[42S02] … Table 'migrations' doesn't exist`, because the bookkeeping table only existed after a separate `migrate --install`. `migrate` now creates it itself when missing (Laravel parity); `--install` remains as an explicit, idempotent alias. Also fixes the `Migrations table exits` → `exists` message typo.
- **No `composer create-project` autoload warning.** The skeleton's `tests/ExampleTest.php` declared a non-namespaced class while `composer.json` maps `Tests\ => tests/`, so scaffolding printed `Class ExampleTest … does not comply with psr-4 autoloading standard … Skipping`. The test is now `namespace Tests;` (PSR-4 compliant); harmless before (Pest ran it anyway), just noisy on first impression.

## [4.6.0] - 2026-06-13

### Changed
- **BREAKING — migration/dump folders swapped to the Laravel layout.** The Ions database convention was inverted from Laravel: `make:schema` wrote runnable migration files to `database/schemas/` and `schema:dump` wrote dumps to `database/migrations/`. These roles are now swapped to match Laravel: **runnable migrations live in `database/migrations/`** (created by `make:schema`, run by `migrate`, deleted by `schema:dump --prune`) and **schema dumps live in `database/schemas/`** (written by `schema:dump`, replayed by `migrate:rollback`). Only the command target directories changed — the `App\Database\Schema` stub namespace, the `Path::database()` casing-normalization map values, and the legacy `{app|src}/Database` fallback chain are unchanged (a legacy host now reads runnable migrations from `{app|src}/Database/migrations`). **Action:** existing hosts must move runnable `database/schemas/*.php` → `database/migrations/` and any `database/migrations/*_schema.dump` → `database/schemas/`. See [UPGRADE-4.6.md](UPGRADE-4.6.md).

### Fixed
- **Default CSP no longer renders every first-party page unstyled.** `Ions\Security\SecurityHeaders` defaulted the `Content-Security-Policy` to a bare `default-src 'self'`, which makes the browser block ALL inline `<style>` blocks and `style=` attributes. Because the framework's own pages — the welcome/start page, the layout, the production error pages, and the dev debug page + toolbar — embed their CSS inline by design (an error page must render even when the asset pipeline is broken, so it cannot link an external stylesheet), every first-party page rendered as unstyled HTML in the browser out of the box (curl/source looked fine — only the browser enforces CSP). The default is now `default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:` (with `script-src 'self' 'unsafe-inline'` added under `APP_DEBUG` so the interactive debug page/toolbar work locally). Inline-style is a low-risk relaxation; production inline-script stays blocked. Override via `config('app.security.csp', …)`. (Surfaced by browsing the Taskflow reference app.)

### Added
- **Framework UI & debugging experience (Phase 14)** — a cohesive, first-party developer UI across the debug page, debug toolbar and production error pages, built on a shared `Ions\Http\Ui` design system (`DesignSystem` tokens/primitives, `SourceHighlighter`, `EditorLink`) and ALL of it dependency-free, self-contained (inline CSS/JS, no external/CDN assets) and dev-only gated:
  - **Interactive debug page** (`Ions\Http\DebugPage`, `APP_DEBUG` only) — clickable two-pane stack (frame list ↔ source), server-side syntax highlighting via `token_get_all()`, the throw frame selected with app-vs-vendor styling, Request/Headers/Params/Cookies/Session/Context tabs, a copy-as-markdown action and an open-in-editor link per frame (`app.debug.editor`). Interactivity is a tiny inlined vanilla script; with JS off the excerpt, full trace and every tab table are still server-rendered. **Request redaction is preserved** — every displayed value runs through the log redactor (password/token/secret/authorization/api_key) widened with cookie/php-auth/session/csrf; cookie values are blanket-masked. Fail-closed.
  - **Expandable debug toolbar** (`DebugToolbarMiddleware`) — the collapsed footer strip's segments now expand panels above it: queries (SQL list + per-query time + binding counts, values never shown), request (method/path/route/status/content-type) and timing (wall/memory/runtime). Ephemeral state, styles/script scoped under `#ions-debug-toolbar`. The queries panel renders at most the first 100 queries then a `… N more` marker (the header count/time still reflect all queries); `app.debug_toolbar => false` is the escape hatch. Injection safety unchanged (HTML bodies with `</body>` only, stale `Content-Length` stripped, never throws, never touches JSON).
  - **Branded production error pages** (`Ions\Http\Ui\ErrorPage`) — when `APP_DEBUG` is off, 400/401/403/404/405/419/429/500/503 render a JS-free, inline-CSS branded page (status, client-safe message, home link) instead of the bare `<h1>`. Host `views/errors/{status}.twig` and `{4xx|5xx}.twig` overrides are preserved (lookup chain unchanged), with `status` + `message` context; fail-closed (a broken template degrades to the built-in page).
  - **Refreshed start page** — the skeleton/example welcome page aligned to the shared design-system palette.
  - New docs: `docs/errors-and-debugging.md`.

### Fixed
- **Web pages no longer 500 with "Failed to start the session: already started by PHP" under real SAPIs**: the framework kept TWO independent `Session` objects — the legacy static `Kernel::session()` (created and **eagerly started** at boot by `structureBone()`) and the modern bound `SessionManager` used by the web pipeline's `StartSessionMiddleware`. Each owns its own `NativeSessionStorage`, so each calls `session_start()`: under any non-`cli` SAPI (`php -S` / `ions serve`, PHP-FPM) `structureBone()` started a native session at boot, then the middleware's `SessionManager::start()` saw `PHP_SESSION_ACTIVE` and threw — breaking **every** web page (the API/JSON path was unaffected as it uses no session). The test suite never caught it because under the `cli` SAPI `structureBone()` uses an in-memory `MockArraySessionStorage`. Fixed by unifying on a single session: `structureBone()` no longer eagerly starts, `SessionManager` now builds an `Ions\Support\Session` (a Symfony `Session` subtype), and `Kernel::session()` returns that one bound-manager session once the container is booted — so the legacy consumers (`BaseController::$session`) and the web pipeline share the SAME session, started exactly once. (Surfaced by running the Taskflow reference app in a browser.)
- **Lazy `_csrf_token` Twig global → form-less rendered pages are now response-cacheable**: `Ions\View\ViewFactory` previously seeded the `_csrf_token` global EAGERLY by calling `csrfToken('web')` on every Twig environment build/refresh, which generated and wrote the token into the session on EVERY render. A non-empty session makes `Ions\Http\ResponseCache::requestIsStateful()` true, so the Phase 12.5 response cache refused to cache ANY framework-rendered page — even a form-less public page that never references the token (the headline use of the feature). `_csrf_token` is now a lazy, output-only `Stringable` (`Ions\View\CsrfTokenProxy`) whose value is resolved (and the session written) ONLY when a template actually outputs `{{ _csrf_token }}`. Form-less pages leave the session empty and are cached; pages that print the token / render a form via `ionToken()` write per-session state and remain correctly uncacheable. `{{ _csrf_token }}` is unchanged for hosts that use it (BC), and CSRF validation is unaffected (forms use `ionToken()`; `CsrfMiddleware` still validates).
- **Laravel-compatible path globals**: `src/helpers.php` now defines `base_path()`, `app_path()`, `config_path()`, `database_path()`, `public_path()`, `storage_path()` and `resource_path()`, each mapped to `Ions\Bundles\Path` with Laravel semantics (empty arg → directory root with no trailing slash; sub-path joined with a single separator). Fixes a fatal `Call to undefined function Illuminate\Database\Connectors\base_path()` when using file-based SQLite, where Illuminate components call the global `base_path()`.
- **Symlink-safe host boot**: the skeleton's `public/index.php` and `bin/ions` now pass the host root explicitly — `Kernel::boot(dirname(__DIR__))` — instead of the bare `Kernel::boot()`. The bare form resolves the host root five directories up from the core package, which is correct for a normal `vendor/` install but wrong when the core is installed via a **symlinked path repository** (local dev / monorepo), where `__DIR__` resolves the symlink to the core's real location. Hosts should pass their root from their own entry points.

## [4.5.0] - 2026-06-11

The Phase 12 release: hardening, debt paydown, and three additive feature
sets. It closes the legacy `Bundles/` security audit (path-traversal
containment for upload/disk writes/moves/copies/downloads, SVG/HTML/JS/XML
added to the upload deny-list, fail-closed upload content validation, and a
genuinely presigned `IonDisk::getSignedUrl()`), then ships TOTP two-factor
auth, email verification, and opt-in HTTP response caching — and promotes
worker mode from experimental to **stable**. The PHPStan baseline is now empty
and `Kernel` was decomposed into focused collaborators (a pure refactor). The
security audit also carries a few **behavior changes** for hosts — fail-closed
upload validation, `Path::files()`/`filesRoot()` now reject `..`/absolute
arguments, and `getSignedUrl()` now signs — detailed in
[UPGRADE-4.5.md](UPGRADE-4.5.md). No new dependencies.

### Added

- **TOTP two-factor authentication** — `Ions\Auth\TwoFactor`, a dependency-free
  RFC 6238 verifier (static, stateless): `generateSecret()`, `code()`,
  `verify()` with a configurable drift window, `otpauthUri()` for authenticator
  QR provisioning, `generateRecoveryCodes()` / `hashRecoveryCode()` /
  `verifyRecoveryCode()` single-use recovery codes, and Base32 codec helpers. A
  `Ions\Auth\TwoFactorReplayStore` (`verifyOnce()` / `markUsed()` /
  `wasUsed()`) blocks the same time-step code being replayed within its window,
  backed by the cache store. Ships a `two_factor_columns` migration stub. These
  are additive building blocks — no login flow is changed unless the host wires
  them in. See [docs/two-factor.md](docs/two-factor.md).
- **Email verification** — `Ions\Auth\EmailVerification` issues and validates
  signed verification links **bound to the current email** (changing the email
  invalidates outstanding links); a `Ions\Auth\Contracts\VerifiesEmail` user
  contract (`getEmailForVerification()` / `getKeyForVerification()` /
  `hasVerifiedEmail()` / `markEmailVerified()` / `getEmailVerifiedAt()`); the
  `Ions\Auth\Http\EnsureEmailVerified` middleware (register under the
  `verified` alias) to gate routes; a `VerifyEmail` notification; and a resend
  throttle. Ships an `email_verified_at_column` migration stub. Additive — a
  user model that does not implement `VerifiesEmail` is never gated. See
  [docs/email-verification.md](docs/email-verification.md).
- **HTTP response caching** — `Ions\Http\ResponseCache` + the
  `Ions\Http\Middleware\CacheResponseMiddleware` (register under the
  `cache.response` alias), opt-in **per route**. Caches only safe, shareable
  responses (idempotent GET 200s with no session, no resolved auth user and no
  `Set-Cookie`), strips per-client/hop-by-hop headers, and serves an
  `ETag`/`304 Not Modified` revalidation path. A `cache:clear-responses`
  command purges only the response-cache tag on a tag-capable store
  (redis/memcached). Benchmarks show ≈ **10–12× faster** per cached request.
  See [docs/response-cache.md](docs/response-cache.md) and
  [docs/performance.md](docs/performance.md).
- **Worker mode promoted to stable** — `Ions\Runtime\WorkerRunner` and
  `Kernel::resetForRequest()` are no longer experimental. A multi-subsystem
  isolation matrix (`WorkerLeakMatrixTest`) proves per-request state is reset
  across config, container, session, auth, DB and request subsystems, and
  `ions doctor` gained a worker-readiness check. Documented **FrankenPHP** and
  **RoadRunner** worker recipes (both doc-only, neither a dependency). See
  [docs/worker-mode.md](docs/worker-mode.md).

### Changed

- **Upload content validation is now fail-closed** — when an extension is on
  the allow-list but has **no entry in the MIME map**, `UploadValidator` now
  **rejects** the upload (previously it accepted it — fail-open). Hosts that
  allow-list an extension absent from `app.uploads.mime_map` must add a mapping.
  See [UPGRADE-4.5.md](UPGRADE-4.5.md).
- **`Path::files()` / `Path::filesRoot()` now reject traversal** — relative
  subpaths are still allowed, but a `..` segment, an absolute path, or a
  null-byte argument now throws a `RuntimeException` instead of resolving.
  Hosts passing such values must stop. See [UPGRADE-4.5.md](UPGRADE-4.5.md).
- **`IonDisk::getSignedUrl()` now returns a presigned, expiring URL** — it
  previously returned an unsigned permanent URL. Callers relying on the old
  always-public link must adjust. See [UPGRADE-4.5.md](UPGRADE-4.5.md).
- **Worker mode is stable** — `WorkerRunner` is no longer marked
  `@experimental`; the reset lifecycle is covered by the isolation matrix.
- **`Kernel` decomposed (internal, no host impact)** — extracted
  `TrustedProxies`, `JwtFactory`, `MiddlewareStack` and `ControllerResolver`
  collaborators from `Foundation\Kernel` (Kernel shrunk ≈ 15%). Pure refactor;
  no behavior change.
- **PHPStan baseline burned down to empty (internal)** — the static-analysis
  baseline now holds zero entries; remaining vendor seams are inline-documented.
  No host impact.

### Security

- **Upload/disk path-traversal closed across write/move/copy/download** — the
  legacy `Bundles/` audit centralized containment in `Path`: `Path::files()` /
  `Path::filesRoot()` reject `..`/absolute/null-byte arguments, and
  `IonUpload`/`IonDisk` write, move, copy and download paths are constrained to
  their configured root. A crafted target path can no longer escape the
  uploads/disk root.
- **SVG/HTML/JS/XML added to the upload deny-list** — these stored-XSS vectors
  (`svg`, `svgz`, `xml`, `html`, `htm`, `xhtml`, `js`, `mhtml`) are now on the
  hard-coded `UploadValidator` deny-list and can never be accepted, even if
  allow-listed.
- **Upload content validation fails closed** — an allow-listed extension with
  no MIME-map signature is now rejected rather than accepted (no fail-open
  gap).
- **`IonDisk::getSignedUrl()` now actually presigns** — it issues a genuinely
  signed, time-limited URL instead of the previous unsigned permanent link, and
  no longer leaks static bucket/base-path state between calls.

  See [docs/security-audit-bundles.md](docs/security-audit-bundles.md) and
  [UPGRADE-4.5.md](UPGRADE-4.5.md).

## [4.4.0] - 2026-06-11

The Phase 11 release: the Laravel-standard host-root `database/` layout, plus
the merged security work (also released as 4.3.1). Migrations, seeders,
factories, schema dumps and backups move to a single `database/` tree at the
host root, with the `Database\Factories\` / `Database\Seeders\` namespaces;
`make:model` retargets to `app/Models` (`App\Models`) on a hardened generator
base; and the JWT refresh flow gains rotation with refresh-token-family reuse
detection (the "revoke-all-on-replay" breach pattern). No new dependencies.
The behavior changes — the `database/` precedence, the composer.json namespace
mappings, the `make:model` target + dropped DB introspection, and the
`Jwt::refresh()` return-shape / `/api/auth/refresh` JSON change — are detailed
in [UPGRADE-4.4.md](UPGRADE-4.4.md).

### Added

- **Host-root `database/` layout** — `Path::database()` now resolves the
  Laravel-standard host-root `database/` tree (`migrations/`, `seeders/`,
  `factories/`, `schemas/`, `backups/`) when `{root}/database` exists
  (`Path::usesDatabaseLayout()`), taking **precedence** over the legacy
  `{app|src}/Database`. The legacy `Schema`/`Seeders`/`Factories`/`Backups`
  subfolder names map onto the lowercase standard directories; the legacy
  layout stays byte-identical as the preserved fallback. `MigrateCommand`,
  `SeederCommand`, `MakeFactoryCommand`, `DumpCommand` and `SchemaCommand` all
  key their target directory off this. `ions doctor` gained a
  `dual_database_dirs` check that warns when both `database/` and a legacy
  `{app|src}/Database` exist (database/ wins; consolidate and remove the unused
  directory). See [docs/console.md](docs/console.md) and
  [UPGRADE-4.4.md](UPGRADE-4.4.md).
- **`Database\Factories\` / `Database\Seeders\` namespaces** — `HasIonsFactory`
  resolves a model's factory Laravel-parity: an explicit `protected static
  string $factory` wins, then the top-level `Database\Factories\{Model}Factory`
  (the 4.4 layout), then the 4.2 `{ModelNamespace}\Factories\{Model}Factory`
  fallback. `make:factory` generates into `database/factories/` (namespace
  `Database\Factories`) and `make:seeder` into `database/seeders/` (namespace
  `Database\Seeders`) on the new layout. Host apps adopting the layout add
  `"Database\\Factories\\": "database/factories/"` and
  `"Database\\Seeders\\": "database/seeders/"` to their composer.json
  `autoload.psr-4`. See [docs/factories.md](docs/factories.md) and
  [UPGRADE-4.4.md](UPGRADE-4.4.md).
- **`make:model` → `app/Models` (`App\Models`)** — `ModelCommand` now generates
  models into `app/Models` (namespace `App\Models`, `Path::src('Models/…')`
  preserving the `src/`→`app/` fallback) on a shared `GeneratorCommand` base
  (name validation, `--force` overwrite guard). The stub uses the
  `Ions\Database\HasIonsFactory` trait live. A new `--factory` flag also
  generates the matching `Database\Factories\{Name}Factory` in the same step.
  See [docs/console.md](docs/console.md) and
  [UPGRADE-4.4.md](UPGRADE-4.4.md).
- **JWT refresh-token family reuse detection** — refresh tokens now carry a
  family id (`fid`, minted at login and carried forward across rotations).
  `Jwt::refresh()` rotates: it revokes the presented refresh token and re-issues
  **both** a new access and a new refresh token in the same family. Replaying an
  already-rotated refresh token is detected as a breach and revokes the **whole
  family** (every sibling token). `RevocationStore` gained `revokeFamily()` /
  `isFamilyRevoked()`, implemented by `ArrayRevocationStore` and
  `CacheRevocationStore`. Pre-4.4 refresh tokens with no `fid` still refresh
  (they join the family-aware scheme on first rotation). See
  [docs/auth.md](docs/auth.md) and [UPGRADE-4.4.md](UPGRADE-4.4.md).

### Changed

- **`make:model` target moved + DB introspection removed** — models now land in
  `app/Models` (`App\Models`) instead of the previous location, and the command
  no longer introspects the database to auto-fill `$table`/`$fillable`/`$hidden`
  from a live schema; the generated stub ships placeholder properties to fill in.
  See [UPGRADE-4.4.md](UPGRADE-4.4.md).
- **`Jwt::refresh()` return shape changed `string` → `array{access, refresh}`**
  — `refresh()` now re-issues both tokens and returns the pair, rather than a
  single access-token string. Callers must read `['access']` / `['refresh']`.
  See [UPGRADE-4.4.md](UPGRADE-4.4.md).
- **`POST /api/auth/refresh` JSON adds `refresh_token`** — the refresh endpoint
  now returns the rotated `refresh_token` alongside `access_token`; clients must
  store the **new** refresh token after each refresh (the old one is revoked and
  replaying it revokes the family). See [UPGRADE-4.4.md](UPGRADE-4.4.md).
- **Custom `RevocationStore` implementations must add `revokeFamily()` /
  `isFamilyRevoked()`** — the interface grew two methods for family-based
  revocation; the bundled array and cache stores implement them. See
  [UPGRADE-4.4.md](UPGRADE-4.4.md).

### Security

- **Path-traversal arbitrary file deletion fixed** (also released as 4.3.1) — a
  crafted filename passed to `IonUpload::update()`/`remove()` or
  `IonDisk::delete()`/`deleteDirectory()` could escape the uploads/disk root and
  delete arbitrary files. Deletions are now constrained to a single path segment
  (`basename` + the rejection of `.`/`..`) and a `realpath` containment check
  against the resolved root (including a derived uploads root when no local root
  is configured).
- **Accurate `Retry-After`** (also released as 4.3.1) — the rate-limit
  middleware and the per-email forgot-password throttle now emit the true
  remaining window in `Retry-After` instead of the full window, so clients back
  off for the correct duration.

### Fixed

- **MySQL test portability + CI style** (internal) — test-suite fixes for MySQL 8
  portability and `composer cs` (PHP-CS-Fixer) style, keeping the CI MySQL job
  and style gate green.

## [4.3.1] - 2026-06-11

A security patch over 4.3.0 (also rolled into 4.4.0).

### Security

- **Path-traversal arbitrary file deletion fixed** — a crafted filename passed
  to `IonUpload::update()`/`remove()` or `IonDisk::delete()`/`deleteDirectory()`
  could escape the uploads/disk root and delete arbitrary files. Deletions are
  now constrained to a single path segment (`basename` + rejection of `.`/`..`)
  and a `realpath` containment check against the resolved root.
- **Accurate `Retry-After`** — the rate-limit middleware and the per-email
  forgot-password throttle now emit the true remaining window in `Retry-After`
  instead of the full window.

## [4.3.0] - 2026-06-11

The Phase 10 release: framework parity. The highest-value gaps against
Symfony/Laravel/Slim close — trusted proxies, implicit route model binding,
pagination plus the session-backed web form flow with a fluent `redirect()`,
Gate/policy authorization, queue retries + failed-job recovery, an `/up`
health endpoint, a debug toolbar, ORM strict mode in debug, custom error
pages, channel-based logging, fluent routing, IonDisk unified onto the
FilesystemManager, a PSR-15 middleware adapter, maintenance mode and
`ions serve`. No API removals; the behavior changes (ORM strict in debug,
FormRequest web redirects, model binding, the thrown CSRF 419, and a few
smaller ones) are detailed in [UPGRADE-4.3.md](UPGRADE-4.3.md). Three new
dependencies, all small and MIT, serve the PSR-15 adapter only:
`nyholm/psr7` `^1.8`, `symfony/psr-http-message-bridge` `^7.4` and
`psr/http-server-middleware` `^1.0`.

### Added

#### Security

- **Trusted proxies** — `config('app.trusted_proxies')`: a list of proxy IPs/CIDR ranges (or `'*'` to trust the directly connecting peer — the single-LB case) passed to Symfony's `Request::setTrustedProxies()` at boot and re-applied against the actual request in `handle()` (worker-mode/`'*'` correctness). Behind a configured proxy, `X-Forwarded-*` headers are honoured: `isSecure()` is true after TLS termination, so HSTS is emitted and `session.cookie_secure => 'auto'` resolves secure; client IPs come from `X-Forwarded-For`. The header set is `config('app.trusted_proxy_headers')`: `'xff'` (default — For|Host|Port|Proto), `'aws-elb'`, `'traefik'`, `'forwarded'` (RFC 7239), or a raw `Request::HEADER_*` int bitmask — an unknown string **throws** (fail closed: a typo must not silently re-enable the `X-Forwarded-Host` superset). Unset config means no proxy trust, exactly as before. `ions doctor` gained a `trusted_proxies` row (with a caveat line for `'*'`); cleared in `resetForTesting()`. See [docs/config.md](docs/config.md#apptrusted_proxies).
- **Gate & policies** — `Ions\Auth\Gate`, bound lazily as the `'gate'` singleton (AuthProvider): `define('ability', fn ($user, ...$args) => bool)` for closures, `policy(Post::class, PostPolicy::class)` for per-model policy classes (ability name → public policy method receiving `($user, $model, ...$extra)`; subclasses resolve the parent's policy), checked via `allows()`/`denies()`/`authorize()` (a denial in `authorize()` throws the same 403 `HttpException` as `abort(403)`). The user is resolved lazily per check from the request's `auth_user` attribute (set by AuthMiddleware with a configured UserProvider) — everywhere else the caller is a guest, and a guest only reaches a callback/policy method whose first `$user` parameter accepts null (auto-deny otherwise, Laravel parity); `forUser($user)` returns a user-scoped clone. Non-public or case-mismatched policy methods deny, never throw. Ergonomics: the global `can('update', $post)` helper, the Twig `can()` function (resolved at render time, worker-safe), and `$this->authorize(...)` on both `BaseController` and `ApiController`. See [docs/auth.md](docs/auth.md#authorization-gate--policies).

#### Smart

- **Implicit route model binding** — an action (or closure-route) parameter whose type is an Eloquent `Model` subclass AND whose name matches a route placeholder receives the record fetched by the model's route key (`getRouteKeyName()`, default primary key): `show(Widget $widget)` on `/widgets/{widget}`. A miss is a 404 (`NotFoundHttpException`); a miss on a nullable parameter injects null; binding without the `'db'` engine booted throws a clear `RuntimeException` naming the model (instead of Illuminate's bare null-resolver Error). All other 9.3 resolver rules (Request, scalar placeholders, services, defaults) are unchanged; a Model hint whose name matches no placeholder still container-makes an empty instance. Custom-key syntax (`{user:slug}`) is out of scope — override `getRouteKeyName()` instead. Behavior note for pre-4.3 signatures in [UPGRADE-4.3.md](UPGRADE-4.3.md). See [docs/controllers.md](docs/controllers.md#route-model-binding).
- **ORM strict mode (debug)** — when `APP_DEBUG` is truthy, `DatabaseProvider::boot()` enables Eloquent's `preventLazyLoading()` + `preventSilentlyDiscardingAttributes()`: a lazy relation access throws `LazyLoadingViolationException` naming the relation, and fills dropped by `$fillable` throw instead of vanishing. Default ON in debug (`'database.strict' => false` is the escape hatch); production is always relaxed regardless of config. Upstream nuance: Eloquent only arms models hydrated from multi-model results — a lazy load off a single `first()`/`find()` never throws. Complements the 8.6 N+1 log heuristic. See [docs/config.md](docs/config.md#databasestrict) and [UPGRADE-4.3.md](UPGRADE-4.3.md).
- **`/up` health endpoint** — built-in route: `GET /up` answers a plain 200 `ok` liveness probe through the real middleware pipeline; `GET /up?checks=1&token=...` runs the full `ions doctor` suite and answers the same JSON shape as `doctor --json` (`{checks, summary, ok}`) — always 200, so monitors distinguish "down" (non-200) from "up but misconfigured" (`ok: false`). Checks are token-gated: `config('app.health.token')` must be set and match (constant-time compare; empty token never opens the gate; 403 otherwise). Responses are `Cache-Control: no-store` and the kernel's web cache defaults now respect that, so a CDN can never mask an outage. Disable the route entirely with `app.health.enabled => false`; a host route on `/up` still wins (built-ins are appended after host routes). The probe stays reachable during maintenance mode. See [docs/console.md](docs/console.md#the-up-health-endpoint).
- **Debug toolbar** — `DebugToolbarMiddleware`, appended to the web stack only when `APP_DEBUG` is truthy at stack-build time (production never constructs it): injects a small fixed footer bar before `</body>` showing request wall time, the matched route, query count + total ms (when `database.query_log` is on; "log off" otherwise), peak memory and the PHP/Ions versions. It never breaks a response: HTML-only injection (JSON/redirect/streamed bodies pass byte-identical), a stale `Content-Length` is dropped, and the whole injection is try/catch'd. In-debug escape hatch: `app.debug_toolbar => false`. See [docs/config.md](docs/config.md#appdebug_toolbar).

#### Easy

- **Pagination** — Illuminate's paginator works out of the box: `DatabaseProvider` wires the page/path/query-string resolvers to the Ions request lazily (read at call time — worker-safe; CLI falls back to page 1), so `$query->paginate(15)` just works, and the new Twig `pagination(paginator)` function (`Ions\View\PaginationExtension`) renders prev/next + a windowed page list with the current query string preserved on every link. The default markup is overridable by committing a `views/pagination.twig`. Paginators returned from API actions keep serializing through resources/JSON as before. See [docs/views.md](docs/views.md#pagination-43).
- **Web form flow: flash, `old()`, `errors()`** — session-backed flash data (`flash('status', 'Saved.')` to write, `flash('status')` to consume; survives exactly one request) plus the failed-form round trip: a redirect built with `->withErrors($bag)->withInput()` flashes the error bag and request input, and the next render reads them via the `errors()` (always returns an `Ions\Http\ErrorBag` — `has/first/all`, never needs an existence check) and `old('field', $default)` helpers — both also Twig functions resolved lazily at render time. Password-ish fields are never flashed (`app.forms.dont_flash`, default `password`/`password_confirmation`/`current_password`). The session is now saved on the exception path too (`StartSessionMiddleware` saves in `finally`), so flash written before a throw survives. See [docs/forms.md](docs/forms.md).
- **Fluent `redirect()`** — the `redirect()` helper returns a 302 `Ions\Http\RedirectResponse` for a path (`redirect('/dashboard')`), or with no arguments an `Ions\Http\Redirector` builder: `->route($name, $params)` (named-route URL), `->back($fallback)` (Referer-based, with an open-redirect guard — only same-origin Referers are honoured, anything else goes to the fallback), `->away($url)` (external, untouched), `->to($path, $status)`. Every redirect chains `->with()`/`->withErrors()`/`->withInput()`/`->withHeaders()`. A global `back()` helper mirrors `redirect()->back()`. The legacy static `Ions\Bundles\Redirect` (send/exit-based) is untouched. Sibling helper: `route($name, $params)` generates an absolute URL for any named route (the unsigned sibling of `signedRoute()`). See [docs/forms.md](docs/forms.md#the-fluent-redirect-api).
- **Custom error pages** — production HTML errors now check the host's views first: `views/errors/{status}.twig` (404, 419, 503, …), then a status-class `views/errors/{4xx|5xx}.twig`, then the built-in minimal page (unchanged bytes). Templates receive `status`, `message` (the same client-safe message the minimal page shows — nothing new is exposed) and `request_path`. Rendering can never throw: any template failure logs a warning to `view.log` and falls back to the built-in page. Debug mode keeps the rich DebugPage; API/JSON errors are byte-identical. The skeleton ships an example `views/errors/404.twig`. See [docs/views.md](docs/views.md#custom-error-pages-43).
- **Welcome page** — the skeleton's home view is now a polished, dependency-free landing page (inline CSS, dark-friendly): wordmark, quick-start snippet and docs/doctor pointers, still rendered through the 4.2 `$this->view()` showcase.

#### Core services

- **Fluent routing** — `Route::get(...)->name('users.show')` names the just-added route fluently (re-keys the collection; the 4th-argument form still works), `->where('id', '\d+')` / `->where(['year' => '\d{4}'])` adds placeholder regex constraints (Symfony requirements — enforced by the live matcher, the compiled `route:cache` matcher and the `route()` generator alike). `Route::redirect($from, $to, $status)` and `Route::view($uri, $template, $data)` register cacheable shortcut routes (framework controllers + route defaults — `route:cache` compiles both). `Route::fallback($handler)` registers a deferred GET catch-all appended after host routes, attribute routes and built-ins, so every real route wins. Groups gained name and middleware prefixes: `Route::prefix('/admin')->name('admin.')->middleware(['auth'])->group(...)` — group middleware merges with per-route middleware, and stacked name prefixes prepend to explicitly named routes. See [docs/routing.md](docs/routing.md).
- **Channel logging** — `config/logging.php` (`default` + `channels`) with drivers `single`, `daily` (rotating, `days`), `stderr` and `stack` (fan-out to other channels), each with its own `level`; relative paths resolve under `var/logs/`. Consumed through the `Ions\Support\Log` facade — `Log::info(...)` (default channel), `Log::channel('audit')->error(...)`, `Log::stack(['app', 'stderr'])->warning(...)` — over the lazily bound `Ions\Log\LogManager` (`LogProvider`, now in the default provider set). Every channel gets the 4.1 secret-redaction processor plus new request-id correlation: one `extra.request_id` per request across all channels, reset per request in worker mode. With no `config/logging.php` a built-in `app` channel (single → `var/logs/app.log`) keeps zero-config logging working; `Logs::create()` is byte-compatible except that its lines now also carry `extra.request_id`. Unknown channels/drivers fail loud. See [docs/logging.md](docs/logging.md).
- **Unified filesystem** — `IonDisk` and `IonUpload` now resolve disks through the shared `Ions\Filesystem\FilesystemManager`, closing the 8.4 caveat: `Storage::fake()` intercepts IonDisk/IonUpload reads and writes in tests (a faked disk always wins; otherwise `local` resolves as the manager's named disk when the host config declares a driver for it, and legacy shapes — s3 with runtime-mutable bucket/basePath — are still built from IonDisk's own config view). The default-disk name now falls back through `filesystem.default` → the legacy `filesystem.disks.default` (the `FILESYSTEM_DISK` env convention) → `'local'`. `Ions\Filesystem\Storage` grew Laravel-parity methods: `putFile()` (random-name store), `download()` (StreamedResponse attachment with safe Content-Disposition), `files()`/`directories()` listings, `copy()`/`move()`, `url()` (public URL with `app.app_url` fallback) and `temporaryUrl()` (signed expiring URL — s3; other drivers throw a clear RuntimeException). IonDisk remains BC but is a removal candidate for 5.0 — prefer `Storage`. See [docs/filesystem.md](docs/filesystem.md) and [UPGRADE-4.3.md](UPGRADE-4.3.md).
- **Queue resilience: retries, backoff, failed jobs** — jobs declare `public int $tries` and/or `public int|array $backoff` on the class (captured into the payload at dispatch; they win over the CLI flags), and `queue:work` gained `--backoff=N` next to `--tries=N`. When a job exhausts its tries the worker records it — connection, queue, full payload, exception, `failed_at` — in the failed-jobs store: `config('queue.failed')` with drivers `database-uuids` (default — matches the bundled jobs-table stub's `failed_jobs` schema, which previously existed but was never written to), `database` (legacy tables without a uuid column) or `null` (discard). Recovery commands: `queue:failed` (list), `queue:retry {id…|--all}` (re-push with attempts reset), `queue:forget {id}`, `queue:flush [--hours=N]`. The `sync` driver still throws inline to the dispatcher. See [docs/cache-queue-events.md](docs/cache-queue-events.md#failed-jobs).

#### Ops

- **Maintenance mode** — `ions down [--secret=S] [--retry=N]` writes `var/maintenance.php`; `Kernel::handle()` gates every request (web and api) before routing — one `file_exists()` per request when live — and answers a 503 `HttpException` rendered by the ExceptionHandler, so `views/errors/503.twig` theming and the standard API JSON shape both apply, with `Retry-After` when `--retry` was given. `--secret` enables a bypass: visiting `/{secret}` sets an HttpOnly cookie (an HMAC token bound to this down cycle — never the raw secret, and a cookie minted before `ions up` can't replay into a later window) and redirects home (subfolder-aware); cookied requests pass through. `/up` stays reachable so ops can monitor the box during maintenance. `ions up` ends it; `ions doctor` gained a maintenance row. See [docs/deploy.md](docs/deploy.md#maintenance-mode).
- **PSR-15 middleware adapter** — `Ions\Http\Middleware\Psr15Adapter` runs any PSR-15 middleware inside the Ions pipeline via `symfony/psr-http-message-bridge` + `nyholm/psr7`: the request is bridged to PSR-7, the vendor middleware processes it, and changes propagate both ways (request mutations reach the controller — session/locale are carried over explicitly; the PSR-7 response is bridged back). Wraps an instance or a class-string (container-resolved at handle time, failing closed when the result is not PSR-15). For `middleware_aliases`, subclass it and pin the vendor class in the constructor. Each adapted middleware pays a double PSR-7/Symfony conversion — prefer native middleware on hot paths. See [docs/middleware.md](docs/middleware.md#psr-15-middleware--psr15adapter).
- **`ions serve`** — runs the app on PHP's built-in development server (`php -S {host}:{port} -t public/`), `--host`/`--port` configurable. Development only.

### Changed

- **Failed web validation now redirects back instead of rendering a 422 HTML page** — a thrown Illuminate `ValidationException` (FormRequest, manual `validate()`) on a non-JSON web request renders as a **302 back** (same-origin Referer, `/` fallback) with the errors and input flashed for `errors()`/`old()`. API/JSON requests (`Accept: application/json` or first segment `api`) keep the 422 `{message, errors}` payload byte-identical. See [UPGRADE-4.3.md](UPGRADE-4.3.md).
- **Model-typed action parameters named after a placeholder are now bound** — pre-4.3 they received a new, **empty** model instance from the container; now they receive the fetched record or a 404, and the route performs a query it previously didn't. Opt-out per parameter: rename it (or drop the type-hint). See [UPGRADE-4.3.md](UPGRADE-4.3.md).
- **ORM strict mode defaults on in debug** — lazy loads and silently discarded fills throw under `APP_DEBUG=true` unless `database.strict => false`. Production behavior is unchanged. See [UPGRADE-4.3.md](UPGRADE-4.3.md).
- **The CSRF 419 is thrown, not returned** — `CsrfMiddleware` now throws `HttpException(419)` through the ExceptionHandler instead of returning a bare `Response('CSRF token mismatch.', 419)`, making the failure themeable via `views/errors/419.twig` and consistent on API routes (JSON shape). Clients still see a 419; only hosts inspecting the middleware's direct return value are affected. See [UPGRADE-4.3.md](UPGRADE-4.3.md).
- **`ExceptionHandler` propagates `HttpException` headers** — headers attached to a thrown `HttpException` (`Retry-After` on the maintenance 503, `Allow`, `WWW-Authenticate`, …) now reach the rendered JSON/HTML response; previously they were dropped. See [UPGRADE-4.3.md](UPGRADE-4.3.md).
- **Production HTML errors consult `views/errors/*` first** — pure addition unless a host already has templates at those paths, which now start rendering. See [UPGRADE-4.3.md](UPGRADE-4.3.md).
- **`IonDisk`/`IonUpload` resolve disks through the shared `FilesystemManager`** — same operations, shared resolution (and `Storage::fake()` interception); subtle only for hosts that mutated manager state at runtime. See [UPGRADE-4.3.md](UPGRADE-4.3.md).

### Fixed

- **Group route closures no longer leak their prefix on throw** — `Route::prefix(...)->group()` (and the closure form of `prefix()`) pops its prefix/controller/name/middleware stacks in `finally`, so an exception inside a group closure can't prepend the group's prefix to every subsequently registered route.
- **Closure routes inside a controller-namespaced group no longer fatal** — `Route::prefix('/admin', 'Admin\\')` + a closure route used to concatenate the namespace onto the Closure (a fatal `TypeError`); the namespace prefix now applies to string controllers only.
- **Flash data written before an exception is no longer lost** — `StartSessionMiddleware` saves the session in `finally`, so the exception path (e.g. the new validation redirect) persists its flash.

### Security

- **Redirects are never publicly cacheable** — `Kernel::handle()` no longer applies its `public, max-age=3600` web default to 3xx responses (a shared cache/CDN could have served one user's per-user `Location` to others), and responses that opted out with `no-store` (e.g. `/up`) keep that directive instead of having it overwritten.
- **`back()` is open-redirect-guarded** — the Referer header is attacker-controlled, so the fluent `back()` only follows path-only or verified same-host http(s) Referers (scheme-relative `//host` and foreign schemes go to the fallback).
- **Fail-closed proxy-header parsing** — an unrecognized `app.trusted_proxy_headers` string throws at apply time instead of silently selecting the permissive `'xff'` superset.
- **Maintenance bypass hardening** — the flag file stores only a sha256 hash of the secret, and the bypass cookie is an HMAC bound to the activation timestamp, so it cannot replay across down cycles even when the secret is reused.

## [4.2.0] - 2026-06-11

The Phase 9 release: conventions & developer experience. The `app/` layout
becomes the convention (the legacy `src/` fallback is fully preserved),
actions return views and resources directly, controllers get real container
DI plus a documented lifecycle, a fluent cron scheduler replaces hand-rolled
cron plumbing, two frontend scaffolds wire Vite or plain assets into Twig,
and the deploy story is documented end to end. No API removals; a handful of
edge-case behavior changes are detailed in [UPGRADE-4.2.md](UPGRADE-4.2.md).
One new dependency: `dragonmantank/cron-expression` `^3` (MIT,
dependency-free — the ecosystem-standard cron parser).

### Added

- **`app/` host layout convention** — `Ions\Bundles\Path` resolves `{root}/app` before the legacy `{root}/src` for `Path::src()`/`api()`/`database()` and everything built on them (controller dispatch, attribute-route + provider/command discovery, migrations/seeders, every `make:*` generator). `src/`-only hosts are untouched — the fallback is preserved verbatim. The skeleton ships its code in `app/` (`"App\\": "app/"`), and `ions doctor` gained a `dual_app_dirs` WARN for hosts carrying both directories (where `app/` now wins). See [UPGRADE-4.2.md](UPGRADE-4.2.md).
- **View returns: `view()` + `$this->view()` + namespaced roots** — `Ions\View\View` is a lazy renderable (template + data): return it from an action or closure route and the dispatcher renders it through the shared Twig environment into a 200 HTML response. The `view()` helper translates dots (`view('users.index')` → `views/users/index.twig`) and `@namespace` prefixes; `BaseController::view()` is controller-relative (`UsersController` → `views/users/`, nested controllers use their kebab-cased directory path; `protected string $viewPath` overrides). String keys in `app.twig.paths` now register named loader namespaces (`'admin' => 'views/admin'` → `@admin/...`), resolved from the host root with absolute paths kept (vendor packages can ship templates); missing namespace directories are skipped with a logged warning, never a boot failure. See [docs/views.md](docs/views.md).
- **Controller DI + lifecycle hooks** — controllers are container-built (constructor injection; zero-arg controllers pinned byte-identical), and actions are method-injected via `Ions\Http\ActionArgumentResolver`: `Request` by type-hint, route placeholders by name (scalar hints cast), other object type-hints via `app()->make()`, defaults and nullables respected, clear exception naming the parameter otherwise. Four new duck-typed hooks (public methods only): `boot()` (method-injected, after `_loadedState`), `beforeAction(): ?Response` (short-circuits the action, `_endState` still runs), `afterAction(Request, Response): ?Response` (decorates/replaces the normalized response), and `middleware(): array` (per-controller middleware — aliases/FQCNs/instances, resolved **fail-closed** like per-route middleware, run as a sub-pipeline around the action phase). The legacy underscore hooks are untouched in name, order and signature. Closure routes share the same method injection and return normalization (`Ions\Http\ResponseNormalizer`) — closures can now return `Responsable`/`View` too. See [docs/controllers.md](docs/controllers.md).
- **Cron scheduler** — `Ions\Schedule\Scheduler` + `Task`: define tasks in `App\Schedule::boot(Scheduler $schedule)` (class configurable via `app.schedule_class`) with `$schedule->command('emails:send', ['--force' => true])` / `$schedule->call($fn, 'name')` and fluent frequencies (`everyMinute`/`everyFiveMinutes`/`everyTenMinutes`/`everyThirtyMinutes`/`hourly`/`daily`/`dailyAt('03:00')`/`weekly`/`monthly`/raw `cron()`, validated immediately). `withoutOverlapping(int $ttl = 3600)` takes an owner-scoped cache lock (a run that outlives its TTL can never release a successor's lock); overlapping invocations are skipped, not queued. `schedule:run` executes the due tasks from one crontab line and exits non-zero when any task failed; `schedule:list` prints name/expression/next run; the built-in `/cron/schedule` route runs the same due tasks for hosts without system cron (JSON `{ran, failed, skipped}` summary) while zero-parameter legacy `boot()` hosts keep the exact pre-4.2 dispatch. Failures are isolated per task and logged to `var/logs/schedule.log`; the registry is lazy (zero hot-path cost) and `Ions\Support\Schedule` is the facade. New dependency: `dragonmantank/cron-expression` `^3`. See [docs/scheduler.md](docs/scheduler.md).
- **Frontend scaffolds + Twig asset functions** — `ions install:vue` writes a Vue 3 + Vite starter (package.json, `vite.config.js` building to `public/build/` with a stable manifest path, `resources/js/app.js` + `App.vue`, idempotent `.gitignore` additions); `ions install:assets` writes plain CSS/JS starters into `public/assets/` (no node, no bundler). Both refuse to overwrite existing files unless `--force` (all-or-nothing: conflicts are listed and nothing is written). `Ions\View\AssetExtension` registers two Twig functions everywhere: `vite(entry)` (manifest-driven CSS links + hashed module script; dev-server HMR mode via a Laravel-style `public/hot` marker the scaffolded config maintains; missing build degrades to an HTML comment + logged warning, never a 500) and `asset(path)` (`app.app_url`-based URL with `?v=filemtime` cache-busting, never throws). The PHP test suite requires no node. See [docs/assets.md](docs/assets.md).
- **Deploy configs** — the skeleton ships `public/.htaccess` (front-controller rewrite with existing-file passthrough, dotfile deny), and [docs/deploy.md](docs/deploy.md) documents the full production setup: hardened nginx server block and Apache vhost (root → `public/`, deny `var/`/`config/`/`.env`, static caching), PHP-FPM pool notes, the TLS-terminating-proxy caveat, the worker-mode pointer, and a deploy checklist ending in `ions optimize && ions doctor`.
- **Best-practices guide** — [docs/best-practices.md](docs/best-practices.md): the opinionated guide to structuring an Ions app — `app/` layout, thin controllers (FormRequest + constructor DI + view/Resource returns), provider wiring, typed config accessors, events vs jobs vs notifications, the testing kit, and security/performance checklists. The README quick tour now shows the 4.2 ergonomics.

### Changed

- **`app/` is checked before `src/`** — hosts carrying **both** directories at the root now resolve to `app/` (previously `src/`); single-directory hosts are unaffected. `ions doctor` warns (`dual_app_dirs`). See [UPGRADE-4.2.md](UPGRADE-4.2.md).
- **Action method injection — argument BC** — actions were previously always invoked with exactly `[$request]`. The placeholder-name match now beats the untyped-first-param legacy rule (`show($id)` on `/users/{id}` receives the placeholder value, not the request), and a variadic first parameter now receives nothing. Type-hint `Request` to keep the old behavior. Closure routes have the same edge: an untyped first parameter named after a placeholder now receives the placeholder value. See [UPGRADE-4.2.md](UPGRADE-4.2.md).
- **Public methods named `boot`/`middleware`/`beforeAction`/`afterAction` are now lifecycle hooks** — duck-typed by name (public visibility required; protected/private helpers are ignored). A route action itself named `boot` (the legacy `App\Schedule::boot` contract) is dispatched once as the action — the hook never fires twice. See [UPGRADE-4.2.md](UPGRADE-4.2.md).
- **`app.twig.paths` string keys now declare view namespaces** — previously array keys were ignored and every entry resolved via `Path::views()`. Plain numeric-key lists keep the old behavior verbatim. See [UPGRADE-4.2.md](UPGRADE-4.2.md).
- **`/cron/schedule` targets a framework controller** — `Ions\Schedule\Http\WebCronController` inspects the host `boot()` signature at hit time: legacy zero-parameter hosts keep the exact old dispatch; `boot(Scheduler)` hosts get the new scheduler run. With no schedule class the route now answers **404** (previously a 500 from the failed controller resolution), and the request attributes report `_controller_name` `'WebCronController'`/`_method_name` `'run'` instead of `'Schedule'`/`'boot'`. See [UPGRADE-4.2.md](UPGRADE-4.2.md).
- **`schedule:run` drives both registries and reports failure** — it runs the new scheduler's due tasks before the legacy `GO\Scheduler` `schedule.php` jobs (which keep working unchanged) and now exits non-zero when a task fails. Don't define the same job in both registries — it would run twice per tick.

## [4.1.0] - 2026-06-10

The Phase 8 release: hot-path performance, experimental worker mode, a second
security-hardening pass, a host-app DX kit (skeleton, testing, generators),
new first-class facilities (HTTP client, encryption + signed URLs, mailables,
notifications, model factories), and convention-smart boot (provider
auto-discovery, `ions doctor`, N+1 detection). No breaking API changes; two
security defaults flip (session cookies, CORS) — see
[UPGRADE-4.1.md](UPGRADE-4.1.md). Measured on the test fixture
(`bench/bench.php`, php 8.3): steady-state `Kernel::handle()` averages
**0.084 ms/request** (N=200), boot **4.4 ms** (N=50), and a render against
the shared Twig environment **~0.001 ms**; the before/after comparisons for
each optimization are in
[docs/performance.md](docs/performance.md#measured-impact-fixtures-php83).

### Added

#### Performance

- **Production caches + `ions optimize`** — `route:cache` compiles both route groups into `var/cache/routes/{web|api}.php` (Symfony `CompiledUrlMatcherDumper`; first-match cost ~0.79 → ~0.20 ms on the fixture); `config:cache` merges every `config/*.php` into one `var/cache/config.php` (boot ~54% faster on the fixture); `ions optimize` runs `route:cache` + `config:cache` + `discover:cache` in one shot and `optimize:clear` removes all three plus the compiled Twig cache; `preload:generate` writes an `opcache.preload` file covering the framework hot path. All caches are ignored while `APP_DEBUG` is truthy; closure routes / closure config values fail the build with the offending route/key named; per-route middleware names are embedded into the compiled match so cached and live matching behave identically. See [docs/performance.md](docs/performance.md).
- **Shared per-process Twig environment** — `ViewFactory::make()` resolves the `view.env` container singleton (registered by `ViewProvider`) instead of building a fresh `Twig\Environment` per render: ~0.001 ms per no-override `make()` (was 0.060–0.069 ms). Per-request globals (`_csrf_token`/`_trans`/`appUrl`) are refreshed via `ViewFactory::refreshRequestGlobals()` in worker mode. See [docs/performance.md](docs/performance.md).
- **Benchmark harness** — `bench/bench.php` measures boot, cold boot + first request, steady-state `handle()` throughput and Twig-environment reuse in-process against the test fixture; the numbers in [docs/performance.md](docs/performance.md#measured-impact-fixtures-php83) come from it.

#### Worker mode

- **Worker-mode safety (EXPERIMENTAL)** — `Kernel::resetForRequest()` clears per-request state between sequential requests in one process (fresh `Request`/`Response`/legacy-session statics, `SessionManager::renew()` swaps in a brand-new inner session and re-points the shared `request_stack` so CSRF token storage follows, per-request Twig globals `_csrf_token`/`_trans`/`appUrl` re-evaluated via `ViewFactory::refreshRequestGlobals()`, query log flushed when enabled) while keeping boot state (config, container singletons, the route memo, the Twig Environment). `Ions\Runtime\WorkerRunner` (`@experimental`) drives a boot-once/handle-many loop over provider/emitter callables with optional `maxRequests` recycling. `Kernel::isBooted()`. See [docs/worker-mode.md](docs/worker-mode.md).

#### Developer experience

- **Host-app skeleton** — `skeleton/`: a minimal, copyable host application (front controller, `config/` with the 4.1 secure defaults pre-filled, `routes/web.php` + `api.php`, an example controller/Twig view, `bin/ions`, `.env.example`, phpunit/Pest wiring with an example test). See [docs/skeleton.md](docs/skeleton.md).
- **Host-app test kit** — `Ions\Testing\TestCase` boots the kernel per test and offers `get/post/put/patch/delete` verb helpers, `withHeaders()`/`withToken()`, and `actingAs($user)` (issues a **real signed JWT** through the configured signer); every request returns an `Ions\Testing\TestResponse` with `assertStatus/assertOk/assertCreated/assertNoContent/assertRedirect/assertSee/assertJson/assertJsonPath/assertHeader` (chainable, failure messages include the response body). See [docs/testing.md](docs/testing.md).
- **Test fakes** — `Queue::fake()`, `Event::fake()`, `Storage::fake()` (on `Ions\Filesystem\Storage` — swaps a disk for an in-memory one), `Mail::fake()` (inheritance-aware Mailable-FQCN assertions), `Notifications::fake()` and `Http::fake()`: each swaps the container binding, records what the code under test did, and exposes Ions-worded assertion helpers (`assertDispatched` for jobs, `assertFired` for events, `assertSent` for mail/HTTP, `assertSentTo` for notifications, `assertStored` for files, …). See [docs/testing.md](docs/testing.md#fakes-queue-event-storage-mail-notifications-http).
- **Generators** — `make:resource`, `make:request`, `make:job`, `make:event`, `make:listener`, `make:test` and `make:factory` join `make:command`/`make:middleware`/`make:service-provider`, all rebased onto an `Ions\Console\GeneratorCommand` base that validates the class name (identifier/FQCN patterns) before touching the filesystem and renders `strict_types` stubs.
- **Rich debug error page** — with `APP_DEBUG=true`, HTML errors render `Ions\Http\DebugPage`: source excerpt around the throw site, full stack trace, the `getPrevious()` chain, and a request summary with secrets redacted (recursive param masking + PHP-auth header masking). Production HTML output and JSON/API error responses are byte-for-byte unchanged. See [docs/lifecycle.md](docs/lifecycle.md).
- **IDE meta** — `.phpstorm.meta.php` ships with the package so PhpStorm infers concrete types for `app('id')` and container `get()`/`make()` lookups.

#### New facilities

- **Outbound HTTP client** — `Ions\Http\Client` over `symfony/http-client` behind the `Ions\Support\Http` facade: immutable fluent builder (`withToken()`, `withHeaders()`, `timeout()`, `retry()` with exponential backoff, `baseUrl()`), terminal `get()`/`post()`/`json()` returning an `Ions\Http\ClientResponse` wrapper (`status()/ok()/json()/throw()`), and `Http::fake()` with Laravel-style URL-pattern responses plus recorded-request assertions (`retry()` is a pure decorator, so it composes with the fake). See [docs/http-client.md](docs/http-client.md).
- **Encryption & signed URLs** — `Ions\Security\Encrypter`: authenticated encryption over libsodium's XChaCha20-Poly1305 IETF AEAD with the key derived from `APP_KEY`; `Ions\Security\UrlSigner` plus the `signedUrl()` / `signedRoute()` helpers generate tamper-proof, optionally expiring links, verified by `ValidateSignatureMiddleware` (the `signed` alias via `app.middleware_aliases`). See [docs/security.md](docs/security.md).
- **Mailables** — `Ions\Mail\Mailable`: declare subject/recipients/Twig view in `build()`, then `send()` through the container mailer or `queue()` via `SendMailableJob` and the queue subsystem; `toSymfonyEmail()` materializes without sending; `Mail::fake()` records sends with inheritance-aware FQCN assertions. See [docs/mail.md](docs/mail.md).
- **Notifications** — `Ions\Notifications\Notification` with `via()`/`toMail()`/`toDatabase()`, built-in mail (recipient routing via `routeNotificationForMail()`, the `Notifiable` contract) and database channels (+ a `Channel` contract for custom ones), the `notify()` helper, a notifications-table stub, deferred (queued) delivery, and `Notifications::fake()`. See [docs/notifications.md](docs/notifications.md).
- **Model factories** — `Ions\Database\Factory` (minimal, Eloquent-compatible): `definition()` + `make()`/`create()`/`count()`/`state()` with Closure-based states, Faker integration, the `HasIonsFactory` model trait, and the `make:factory` generator. See [docs/factories.md](docs/factories.md).

#### Convention-smart boot

- **Zero-config provider auto-discovery** — when `app.providers` is not set, `Ions\Foundation\Discovery::providers()` registers the framework defaults plus providers discovered from the host `{src|app}/Providers/` directory (single glob per boot, `src/` → `app/` fallback preserved) and from installed composer packages declaring `extra.ions.providers` (read once per process from `vendor/composer/installed.json`, memoized). Host providers run last so they can override package/framework bindings. Escape hatches: an explicit `app.providers` list bypasses discovery entirely (BC); `app.discovery => false` keeps pure defaults; `app.dont_discover => ['vendor/package']` skips specific composer packages (exact name match). Test seam `Discovery::useMetadata()` / `Discovery::reset()` (wired into `Kernel::resetForTesting()`). See [docs/packages.md](docs/packages.md) and [docs/config.md](docs/config.md#appproviders). **Warning:** hosts that already register providers from `src/Providers/` manually (e.g. via `App\Booting`) will get them registered a second time under discovery — remove the manual registration, or set `app.providers` / `app.discovery => false`.
- **Provider discovery cache** — `ions discover:cache` freezes the discovered provider list into `var/cache/providers.php` (one `require` at boot, zero scans); `ions discover:clear` removes it; both are wired into `ions optimize` / `ions optimize:clear`. `APP_DEBUG` bypasses the cache like the route/config caches; stale cached FQCNs (provider deleted, package removed without re-running `discover:cache`) are filtered at load with a logged warning, never a fatal — and a cache that filters down to zero providers is rejected entirely (boot falls back to live discovery). The host-provider `require_once` fallback is hardened: top-level output is swallowed and any `Throwable` from a provider file logs a warning naming the file (`var/logs/app.log`) and the scan continues. See [docs/performance.md](docs/performance.md).
- **`ions doctor`** — host-app diagnostics: 18 checks across env (`APP_KEY` length, `app.app_url`), `var/` writability, the three production caches, DB connectivity, PHP extensions, and security posture (CSRF, trusted hosts, session cookie overrides, CORS wildcard, debug mode), plus the provider-discovery state. `--json` for CI; exits non-zero only on critical failures (warnings pass). A throwing check degrades to a FAIL row, never kills the diagnosis. See [docs/console.md](docs/console.md#diagnostics--doctor).
- **Typed config accessors** — `config()->string('app.name')` / `integer()` (`int()`) / `boolean()` (`bool()`) / `array()` / `float()` on `Ions\Foundation\Config`: assertion-style getters that throw `InvalidArgumentException` on type mismatch (no coercion — `'1'` is not an int), mirroring Laravel 11's family. See [docs/config.md](docs/config.md#typed-accessors).
- **Debug-only N+1 query detector** — when `APP_DEBUG` is truthy and `database.query_log` is on, `DatabaseProvider::boot()` attaches `Ions\Database\Listeners\DetectNPlusOne` to the kernel's `RequestHandled` event; at request end it runs `Ions\Database\NPlusOneDetector` over the bounded query log (SELECTs normalized into shape-patterns: literals and `IN (?, ?, …)` lists collapsed) and logs one warning per pattern repeated >= `database.nplusone.threshold` (default 5) to `var/logs/performance.log` (pattern, count, total ms, request path). Log-based heuristic: it flags repeated identical-pattern lookups, not ORM-level lazy loads. Escape hatch `database.nplusone.enabled => false`; with debug or the query log off nothing is attached (zero hot-path cost) and the listener never throws. See [docs/performance.md](docs/performance.md#n1-query-detector-debug-only) and [docs/config.md](docs/config.md#databasenplusoneenabled).

### Changed

- **Query logging is opt-in** — `DatabaseProvider` enables the connection query log only when `config('database.query_log')` is `true` (default `false`); `APP_DEBUG` alone no longer enables it (the log buffers every statement in memory for the process lifetime — unbounded in workers). If you relied on `APP_DEBUG=true` to make `debugQuery()` return statements, add `'query_log' => true` to `config/database.php`. See [UPGRADE-4.1.md](UPGRADE-4.1.md).
- **Routes are captured once per process** — `Kernel::handle()` no longer re-requires the route files and re-scans attribute routes per call; the per-group collection is memoized for the process lifetime and rebuilt on every `Kernel::boot()`. Classic FPM is functionally unchanged; code mutating `Kernel::RouteCollection()` between `handle()` calls must re-boot. See [UPGRADE-4.1.md](UPGRADE-4.1.md).
- **`Kernel::handle()` syncs the shared request** — `Kernel::request()` now returns the request actually being handled instead of the boot-time capture (identical in classic FPM; essential for worker mode). See [UPGRADE-4.1.md](UPGRADE-4.1.md).
- **Unresolvable per-route middleware now fails the request** — resolution failure (unknown alias, missing class, throwing constructor) throws in production too, instead of being logged and silently dropped; detailed under *Security*. See [UPGRADE-4.1.md](UPGRADE-4.1.md).
- **Security default flips** — session cookies are `Secure`/`HttpOnly`/`SameSite=Lax` by default and CORS is deny-by-default; detailed under *Security* and in [UPGRADE-4.1.md](UPGRADE-4.1.md).

### Security

- **Session cookies are secure by default** — the native session driver now defaults to `cookie_secure => true`, `cookie_httponly => true`, `cookie_samesite => 'lax'` when the keys are omitted from `config/session.php`; every default is overridable, and `cookie_secure => 'auto'` follows the current request's scheme (failing secure when no request is available). Plain-HTTP dev hosts must set `cookie_secure => false` (or `'auto'`) explicitly. See [UPGRADE-4.1.md](UPGRADE-4.1.md), including the TLS-terminating-proxy caveat.
- **Login regenerates the session id** — `AuthController::login` calls `SessionManager::regenerate()` after a successful credential check when a started framework session is bound (fixation hardening); session data is preserved, stateless API logins are unaffected.
- **HSTS + Permissions-Policy** — `SecurityHeaders::apply()` additionally emits `Strict-Transport-Security: max-age=31536000; includeSubDomains` (HTTPS requests only; override with a string at `app.security.hsts` or disable with `false`) and `Permissions-Policy: camera=(), geolocation=(), microphone=()` (`app.security.permissions_policy`); like CSP, a header already set by the caller is never overwritten.
- **CORS is deny-by-default** — `app.cors.origins` defaults to `[]` (was `['*']`): with no configured origins no `Access-Control-*` headers are emitted and preflights get a bare `204`. `Access-Control-Allow-Credentials: true` requires an explicit `app.cors.credentials => true` **and** a non-wildcard origin list (the Fetch spec forbids credentials with `*`). Origin-dependent responses carry `Vary: Origin` so shared caches cannot serve one origin's headers to another.
- **Upload magic-bytes validation** — `IonUpload::store()`, `IonDisk::put()` and `IonDisk::putFile()` verify (after the extension allow-list) that the content's `finfo` MIME agrees with the claimed extension — PHP source named `.jpg` is rejected. The extension→MIME map is configurable at `app.uploads.mime_map` (merged over the built-in defaults). See [docs/config.md](docs/config.md).
- **Forgot-password throttling** — `AuthController::forgotPassword` applies a per-(email + IP) limit of 3 requests / 10 minutes via the shared cache (`app.auth.forgot_throttle = ['max' => 3, 'decay' => 600]`), implemented as a TTL-safe `add()` + `increment()` counter (never a permanent lockout); throttled requests get a generic **429** with `Retry-After` — enumeration-safe.
- **Log context redaction** — loggers built by `Logs::create()` run `Ions\Bundles\RedactionProcessor`: context keys matching *password / passwd / token / secret / authorization / api[_-]?key* (case-insensitive, recursive through nested arrays) are masked to `[REDACTED]` before reaching the log file.
- **Supply-chain guardrails** — CI runs `composer audit --locked` on every build, Dependabot watches composer dependencies and GitHub Actions weekly, and [SECURITY.md](SECURITY.md) documents supported versions and the private disclosure process.
- **Per-route middleware resolution is fail-closed** — an unresolvable per-route middleware (typo'd `signed`/`throttle` alias, missing class, throwing constructor) now always throws — rendered as a 500 with the cause logged in production — instead of serving the route unguarded (pre-4.1 production logged and dropped it). Group stacks are pre-constructed instances and unaffected. See [UPGRADE-4.1.md](UPGRADE-4.1.md).
- **Debug page redaction** — the new debug error page masks secret-bearing request parameters recursively and the PHP-auth headers before rendering the request summary (debug mode only; production output unchanged).

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

[Unreleased]: https://github.com/tahadeveloper/ions.core/compare/4.5.0...HEAD
[4.5.0]: https://github.com/tahadeveloper/ions.core/compare/4.4.0...4.5.0
[4.4.0]: https://github.com/tahadeveloper/ions.core/compare/4.3.1...4.4.0
[4.3.1]: https://github.com/tahadeveloper/ions.core/compare/4.3.0...4.3.1
[4.3.0]: https://github.com/tahadeveloper/ions.core/compare/4.2.0...4.3.0
[4.2.0]: https://github.com/tahadeveloper/ions.core/compare/4.1.0...4.2.0
[4.1.0]: https://github.com/tahadeveloper/ions.core/compare/4.0.0...4.1.0
[4.0.0]: https://github.com/tahadeveloper/ions.core/compare/3.0.0...4.0.0
[3.0.0]: https://github.com/tahadeveloper/ions.core/releases/tag/3.0.0
