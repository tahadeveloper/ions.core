# Ions Framework — 4.0.0 Upgrade Guide

This is the upgrade guide for the **3.0.x → 4.0.0** transition.

> **The one breaking change that gates 4.0.0 is the PHP floor: PHP 8.3 is now the
> minimum** (was 8.2 in 3.0). Everything else in 4.0 is additive — new subsystems
> you can adopt at your own pace.

If you are coming from **2.1.x**, do the [2.1 → 3.0 upgrade](UPGRADE-3.0.md) first
(it is the larger, security-driven jump: rebuilt JWT, CSRF, trusted hosts, the
container/provider/middleware architecture, RedBean/Smarty removal). This guide
covers only the deltas added on top of 3.0.

---

## Quick-reference checklist

| Area | Action required |
|---|---|
| **PHP** | **Upgrade the host runtime to PHP 8.3+** — the breaking change that gates 4.0.0. Hosts on 8.2 cannot pull `ionzile/core` 4.0. |
| Illuminate / Laravel | Now on **Illuminate 12** (was 11) — review Eloquent / container / validation deltas (light major; usually none) |
| Auth — Sentinel | Now on **Cartalyst Sentinel v9** (was v8) — no Ions API changes; note Sentinel v9 itself requires PHP 8.3 |
| Filesystem | *(new, non-breaking)* Optional `config/filesystem.php` for multi-driver disks; `IonDisk` stays BC |
| Session | *(new, non-breaking)* Optional `config/session.php`; `StartSessionMiddleware` is auto-added to the web stack when a `session` binding exists |
| Console | *(new, non-breaking)* `bin/ions` runner + `Ions\Console\Kernel`; register commands via `config('console.commands')` / `app/Commands` |
| Cache / Queue / Events | *(new, non-breaking)* Optional `config/cache.php` / `queue.php` / `events.php` providers + helpers; **set `cache.persistent_store` to a persistent driver in production** |
| Auth endpoints | *(new, non-breaking)* `Ions\Auth\Http\AuthController` (login/refresh/logout/password); list public endpoints in `app.auth.public_paths` |
| API resources | *(new, non-breaking)* `Ions\Http\Resource` / `ResourceCollection` / `FormRequest`; `ValidationException` now maps to **422** |
| Media | *(new, non-breaking)* `Ions\Media\Image` over `intervention/image` v3 |

---

## Phase 7 — Coordinated dependency bump (Task 7.1)

### PHP 8.3 minimum (Breaking)

The framework now **requires PHP 8.3** (`composer.json` `require.php` is `^8.3`, and
the build `config.platform.php` is pinned to `8.3`). This is the dominant breaking
change for 4.0.0: hosts still on PHP 8.2 must upgrade their runtime before pulling
`ionzile/core` 4.0.

CI runs the suite on **PHP 8.3 and 8.4**. PHP 8.4 surfaces a small number of
implicit-nullable deprecation notices (in Ions code and in the Sentinel dependency);
these are non-fatal and the full 200-test suite passes on 8.4.

### Illuminate 12 (Breaking — major version)

All `illuminate/*` constraints moved from `^11.0` to `^12.0` (resolved: **v12.62**).
Laravel 12 is a light major: no source changes were required in Ions to adopt it.
The Laravel-12 Rector set (`LaravelSetList::LARAVEL_120`) reports no mandatory
rewrites for the core. Carbon resolves to **3.11**, Symfony stays on **7.x**, and
Monolog stays on **3.x**.

### Cartalyst Sentinel v9 (Breaking — major version)

`cartalyst/sentinel` moved from `^8.0` to `^9.0` (resolved: **v9.0.0**). The Ions
Sentinel adapter (`Auth/Guard/Guard`, `Auth/Providers/SentinelUserProvider`)
required **no changes** — the native facade, migrations, hashing, and
registration/activation flow are API-compatible for the surface Ions uses. The
`GuardTest` and `SentinelUserProviderTest` suites pass unchanged. Note that
Sentinel v9 itself requires PHP 8.3, which is the reason the floor moved (hosts
that keep Sentinel as the auth provider therefore need PHP 8.3). The
`EloquentUserProvider` remains the escape hatch if you need a different provider.

---

## Filesystem — multi-driver disks (Task 7.2, new — non-breaking)

`Ions\Filesystem\FilesystemManager` resolves named disks from
`config('filesystem.disks')` — drivers `local`, `s3`, `ftp`, `sftp`, `memory`,
plus custom drivers via `extend()`. `Ions\Filesystem\Storage` is the static
facade (`Storage::put/get/exists/delete/url`, `Storage::disk('s3')->…`). The
manager is bound as `filesystem.manager`.

**What you need to do:** nothing to keep working — `Ions\Bundles\IonDisk` keeps
its static API and now delegates to the manager. To use multiple disks, add
`config/filesystem.php` with `default` + `disks.*`. See
[docs/filesystem.md](docs/filesystem.md) and
[docs/config.md](docs/config.md#filesystem-config-configfilesystemphp).

---

## Session (Task 7.3, new — non-breaking)

`Ions\Session\SessionManager` wraps a Symfony `Session` with config-driven
drivers (`native` / `array`-or-`mock` for tests). `SessionProvider` binds it as
`session`; the `session()` helper mirrors `config()`. `StartSessionMiddleware` is
**auto-added to the web stack before CSRF** when a `session` binding exists, and
CSRF tokens are now stored in this same session (single source of truth, replacing
the standalone `NativeSessionTokenStorage`).

**What you need to do:** nothing required. Optionally add `config/session.php` to
tune the driver/cookie. In tests/CLI use `'driver' => 'array'` to avoid
"headers already sent". See [docs/session.md](docs/session.md).

---

## Console — `bin/ions` (Task 7.4, new — non-breaking)

`Ions\Console\Kernel` boots the framework container and registers commands; the
`bin/ions` executable is the entry point (Composer symlinks it into
`vendor/bin`). Commands are discovered from `config('console.commands')` and the
host's `app/Commands` directory. New generators include `make:command`, and
`schedule:run` runs the host scheduler.

**What you need to do:** nothing required (the existing in-app console binary
still works). To use the bundled runner, call `vendor/bin/ions`. Register your
own commands via `config('console.commands')`. See [docs/console.md](docs/console.md).

---

## Cache / Queue / Events (Task 7.5, new — non-breaking)

- **Cache** — `CacheProvider` binds the Illuminate `CacheManager` as `cache`; the
  `cache()` helper mirrors `config()`. Config in `config/cache.php`.
- **Events** — `EventProvider` binds the dispatcher as `events`; `event()` /
  `listen()` helpers; `Ions\Events\RequestHandled` is fired at the end of every
  request. Config in `config/events.php`.
- **Queue** — `QueueProvider` binds the `QueueManager` as `queue`; extend
  `Ions\Queue\Job` and `dispatch()` it; run workers with `ions queue:work`.
  Config in `config/queue.php`.

**What you need to do:** nothing required (these are optional providers). **If you
add `config/cache.php`, point `cache.persistent_store` at a persistent driver
(`file`/`redis`/`database`) in production** — JWT revocations and rate-limit
counters reuse this shared store, and the `array` driver would silently disable
both. See [docs/cache-queue-events.md](docs/cache-queue-events.md) and
[docs/config.md](docs/config.md#cachepersistent_store).

---

## Auth — HTTP endpoints + per-user JWT (Task 7.6, new — non-breaking)

`Ions\Auth\Http\AuthController` provides ready-made `login`, `refresh`, `logout`,
`forgotPassword`, and `resetPassword` actions. Access tokens are now bound to the
authenticated **user id** (`Jwt::issue($user->getAuthIdentifier())`), so
`AuthMiddleware` resolves the real user. Password reset works when the provider
implements `Ions\Auth\Contracts\SupportsPasswordReset` (the default
`SentinelUserProvider` does, via Sentinel reminders; `EloquentUserProvider` does
not).

**What you need to do:** wire the example routes (`routes/api.php`), put
`throttle` on `login`, and list the auth endpoints in `app.auth.public_paths` so
`AuthMiddleware` lets them through. `public_paths` entries are **segment-anchored**
(a path matches a prefix only on a full `/` boundary — `/api/auth/login` matches
`/api/auth/login` and `/api/auth/login/...` but not `/api/auth/loginx`). See
[docs/auth.md](docs/auth.md).

---

## HTTP — API resources, form requests, OpenAPI (Task 7.7, new — non-breaking)

- `Ions\Http\Resource` / `ResourceCollection` — shape a single model or a
  collection/`LengthAwarePaginator` into a single `data` envelope (with
  pagination `meta`/`links` for paginators).
- `Ions\Http\FormRequest` — typed, self-validating request objects (`rules()`,
  `authorize()`, `validated()`).
- **Validation mapping change:** Illuminate `ValidationException` now renders as
  **HTTP 422** (`{message, errors}`) for API requests via `ExceptionHandler`, and
  a failed `authorize()` renders as **403**. If you previously caught these
  yourself, you can drop that handling.
- `openapi:generate` command exports the route table as an OpenAPI 3.0 spec.

See [docs/resources.md](docs/resources.md).

---

## Media — image processing (Task 7.8, new — non-breaking)

`Ions\Media\Image` wraps `intervention/image` v3 (resize / scale / crop / cover /
watermark / encode / save), failing into a single `Ions\Media\ImageException`.
This restores the image capability dropped with `verot/class.upload.php` in 3.0.
`IonUpload` gained an optional image hook. Config in `config/media.php`
(`driver` = `gd` | `imagick`). See [docs/media.md](docs/media.md).

---

## Internal hardening (Task 7.8)

Non-breaking for consumers: `src/` is PHP-8.4-clean, `strict_types=1` is enforced
across Support/Bundles/Foundation, the PHPStan baseline burned down from 74 → 25,
the main PHPStan gate is **level 5**, and the core packages (Security, Container,
Http, Auth, Providers, View, Filesystem, Session, Console, Media, Support) are
clean at **level 8**.
