# Ions Framework — Core

[![CI](https://github.com/tahadeveloper/ions.core/actions/workflows/ci.yml/badge.svg)](https://github.com/tahadeveloper/ions.core/actions/workflows/ci.yml)

A lightweight PHP 8.3+ framework built on **Symfony** HTTP and routing components and **Illuminate** 12 (database,
cache, queue, events, console, container) — providing a structured, secure foundation without the full overhead of a
monolithic framework.

---

## Requirements

- PHP 8.3 or 8.4
- Extensions: `openssl`, `zip`
- Composer

---

## Installation

```bash
composer require ionzile/core
```

---

## Quick Start

### Front controller (`public/index.php`)

```php
<?php
require __DIR__ . '/../vendor/autoload.php';

use Ions\Foundation\Kernel;

Kernel::boot();
Kernel::run();
```

`Kernel::boot()` loads `.env`, boots the container, registers service providers, and loads routes. `Kernel::run()`
handles the request and sends the response. Existing front controllers that call `Kernel::make()` continue to work — it
is a thin BC shim around `run()`.

### Route (`routes/web.php`)

```php
<?php
use Ions\Bundles\Route;

Route::get('/hello', 'HelloController@index');

Route::prefix('/api/v1')->group(function () {
    Route::post('/users', 'UserController@store')->middleware(['throttle']);
});
```

### Controller

```php
<?php
use Ions\Foundation\ApiController;
use Ions\Http\Json;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

class HelloController extends ApiController
{
    public function index(Request $request): Response
    {
        return Json::ok(['message' => 'Hello, World!']);
    }
}
```

Controllers may return a `Symfony\Component\HttpFoundation\Response` (including `JsonResponse`) or any object
implementing `Ions\Http\Responsable`. The framework normalises the return value before sending.

---

## Application Layout

```
your-app/
├── public/
│   └── index.php          # Front controller
├── config/
│   ├── app.php            # Framework config (providers, middleware, jwt, …)
│   ├── auth.php           # Auth provider / table / columns
│   └── database.php       # DB connections
├── routes/
│   ├── web.php            # Web routes
│   └── api.php            # API routes
├── app/                   # Application source (or legacy src/)
│   └── Http/              # Controllers (web)
├── views/
│   └── default/           # Twig templates
├── var/
│   └── cache/             # Twig, rate-limit, revocation caches
└── .env
```

The framework resolves application paths relative to the host-app root (five directory levels above `vendor/`). Use
`Ions\Bundles\Path` helpers (`Path::src()`, `Path::config()`, `Path::views()`, etc.) to reference locations portably.
Both directory names are supported: `app/` is checked first (the convention since 4.2), with `src/` as the preserved legacy fallback.

---

## Features

- **PSR-11 container** (`Ions\Container\Container`) with `bind`, `singleton`, `make`
- **Service providers** (`Ions\Container\ServiceProvider`) with two-pass bootstrap
- **Provider auto-discovery** — zero-config registration from `{app|src}/Providers/` and composer packages declaring
  `extra.ions.providers`; escape hatches `app.providers` / `app.discovery` / `app.dont_discover`
- **Production caches** — `ions optimize` (`route:cache` + `config:cache` + `discover:cache`), `optimize:clear`,
  `preload:generate`; all bypassed while `APP_DEBUG` is on
- **Worker mode (experimental)** — `Kernel::resetForRequest()` + `Ions\Runtime\WorkerRunner` for boot-once/handle-many
  runtimes (FrankenPHP/RoadRunner style)
- **Middleware pipeline** — `MiddlewareInterface`, `Pipeline`, per-route `->middleware([...])`
- **Default stacks**: web (TrustedHost + SecurityHeaders + CORS + CSRF), api (+ AuthMiddleware)
- **Routing** — `Route::get/post/put/patch/delete/any/match/resource`, prefix/group nesting, attribute routing (
  `#[Route]`)
- **JWT auth** (`Ions\Security\Jwt`) — access + refresh tokens, revocation deny-list, clock leeway
- **HTTP auth endpoints** — `Ions\Auth\Http\AuthController` (login / refresh / logout / password reset); per-user-bound
  tokens
- **Pluggable auth** — `UserProvider` contract; `SentinelUserProvider` (default) or `EloquentUserProvider`
- **Multi-driver filesystem** — `Ions\Filesystem\Storage` / `FilesystemManager`; `local`, `s3`, `ftp`, `sftp`,
  `memory` + custom drivers
- **Session** — `Ions\Session\SessionManager`; `session()` helper; CSRF stored in the session
- **Console** — `bin/ions` runner + `Ions\Console\Kernel`; command discovery; `make:command`, `queue:work`
- **Cron scheduler** — `App\Schedule::boot(Scheduler)` fluent tasks (`->daily()`, `->withoutOverlapping()`, …);
  `schedule:run` / `schedule:list`; `/cron/schedule` web-cron parity
- **Host-app diagnostics** — `ions doctor` (env, APP_KEY, writable `var/`, caches, DB, extensions, security posture);
  `--json` for CI; exits non-zero on critical misconfig
- **Cache / Queue / Events** — `cache()` / `dispatch()` / `event()`+`listen()` helpers; Illuminate-backed providers
- **Outbound HTTP client** — `Ions\Support\Http` facade over `symfony/http-client` (retry/timeout/token builder);
  `Http::fake()` for tests
- **Mailables** — `Ions\Mail\Mailable` (build/send/queue with Twig views); `Mail::fake()` FQCN assertions
- **Notifications** — `Ions\Notifications\Notification` with mail + database channels, custom channels, `notify()`;
  `Notifications::fake()`
- **Model factories** — `Ions\Database\Factory` (`make`/`create`/`count`/`state`), `HasIonsFactory`, Faker integration
- **Testing kit** — `Ions\Testing\TestCase` + `TestResponse` (verb helpers, `actingAs()` real JWT) plus
  Queue/Event/Storage/Mail/Notifications/Http fakes
- **N+1 query detector** — debug-only repeated-pattern warnings to `var/logs/performance.log`
- **API resources** — `Ions\Http\Resource` / `ResourceCollection` (single `data` envelope, pagination meta/links);
  `FormRequest`; `openapi:generate`
- **Image processing** — `Ions\Media\Image` over `intervention/image` v3 (resize / crop / cover / watermark / encode)
- **Typed config accessors** — `config()->string('app.name')` / `integer()` (`int()`) / `boolean()` (`bool()`) /
  `array()` / `float()`; throw `InvalidArgumentException` on type mismatch (no coercion)
- **JSON helpers** — `Json::ok()` / `Json::error()`
- **Twig views** — `Ions\View\ViewFactory`; bound as `view` in the container
- **Security headers** on every response; configurable CSP
- **CSRF enforcement** on web routes (opt-out via `app.csrf.enabled = false`)
- **Upload validation** — extension allow-list + hard-coded executable deny-list
- **Encryption & signed URLs** — `Ions\Security\Encrypter` (XChaCha20-Poly1305 AEAD), `UrlSigner` + `signedRoute()`
  helper + `signed` middleware alias
- **Rate limiting** — `RateLimitMiddleware` / `throttle` alias, 429 + `Retry-After`
- **Exception handler** — `Ions\Http\ExceptionHandler`; JSON for API (incl. 422 validation), HTML for web; safe in
  production
- **Generators** — `make:middleware`, `make:service-provider`, `make:command`, `make:resource`, `make:request`,
  `make:job`, `make:event`, `make:listener`, `make:test`, `make:factory`
- **Host-app skeleton** — `skeleton/`: a minimal bootable host layout with the 4.1 secure defaults pre-filled
- **Debug error page** — source excerpt, stack/previous chain, redacted request summary (`APP_DEBUG=true` only)
- **IDE support** — `.phpstorm.meta.php` ships with the package, so PhpStorm infers concrete types for `app('id')` and
  container `get()`/`make()` lookups automatically
- **CI** — PHPStan (level 5 full / level 8 core), PHP-CS-Fixer, Rector (Laravel 12), Pest 3 (PHP 8.3 + 8.4 × SQLite +
  MySQL 8)

---

## Documentation

| Document                                                 | Contents                                                                                                                                                           |
|----------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| [docs/skeleton.md](docs/skeleton.md)                     | Host-app skeleton (`skeleton/`): layout, quick-start, secure defaults                                                                                              |
| [docs/testing.md](docs/testing.md)                       | Host-app test kit: `Ions\Testing\TestCase`, verb helpers, `actingAs()` (real JWT), `TestResponse` assertions                                                       |
| [docs/factories.md](docs/factories.md)                   | Minimal model factories: `Ions\Database\Factory`, `make()`/`create()`/`count()`/`state()`, `HasIonsFactory`, `make:factory`                                        |
| [docs/lifecycle.md](docs/lifecycle.md)                   | Boot sequence, request pipeline, response dispatch                                                                                                                 |
| [docs/routing.md](docs/routing.md)                       | Route registration, prefixes, groups, middleware, attributes                                                                                                       |
| [docs/controllers.md](docs/controllers.md)               | Controller lifecycle (legacy + `boot`/`beforeAction`/`afterAction`), constructor/action DI, `middleware()`, return normalization                                   |
| [docs/middleware.md](docs/middleware.md)                 | `MiddlewareInterface`, pipeline, default stacks, writing middleware                                                                                                |
| [docs/views.md](docs/views.md)                           | Twig views: `view()` renderable returns, namespaced roots (`app.twig.paths`), controller-relative `$this->view()`                                                  |
| [docs/assets.md](docs/assets.md)                         | Frontend assets: `install:vue` (Vue 3 + Vite, hot-file dev mode), `install:assets` (no-build starters), `vite()`/`asset()` Twig functions                          |
| [docs/auth.md](docs/auth.md)                             | UserProvider, JWT, AuthController endpoints, AuthMiddleware, rate limiting, CSRF                                                                                   |
| [docs/filesystem.md](docs/filesystem.md)                 | Multi-driver disks, `Storage`, `FilesystemManager`, uploads                                                                                                        |
| [docs/session.md](docs/session.md)                       | `SessionManager`, `session()`, `StartSessionMiddleware`, CSRF                                                                                                      |
| [docs/cache-queue-events.md](docs/cache-queue-events.md) | `cache()` / `dispatch()` / `event()`+`listen()`, jobs, `queue:work`                                                                                                |
| [docs/mail.md](docs/mail.md)                             | `Mailable` classes (build/send/queue, Twig views), `Mail` facade, `Mail::fake()` FQCN assertions, `newMailerDsn()`                                                 |
| [docs/notifications.md](docs/notifications.md)           | `Notification` classes (`via()`/`toMail()`/`toDatabase()`), mail recipient routing, notifications table stub, custom channels, `notify()`, `Notifications::fake()` |
| [docs/console.md](docs/console.md)                       | Console Kernel, `bin/ions`, command discovery, `make:command`, `doctor` diagnostics                                                                                |
| [docs/scheduler.md](docs/scheduler.md)                   | Cron scheduler: `App\Schedule::boot(Scheduler)`, frequencies, `withoutOverlapping()`, `schedule:run`/`schedule:list`, web-cron                                     |
| [docs/resources.md](docs/resources.md)                   | API resources, collections, form requests, `openapi:generate`                                                                                                      |
| [docs/media.md](docs/media.md)                           | Image processing over `intervention/image` v3                                                                                                                      |
| [docs/http-client.md](docs/http-client.md)               | Outbound HTTP: `Http` facade over `symfony/http-client`, response wrapper, `Http::fake()`                                                                          |
| [docs/security.md](docs/security.md)                     | `Encrypter` (sodium AEAD), `UrlSigner`, `signedRoute()`/`signedUrl()`, `signed` middleware                                                                         |
| [docs/config.md](docs/config.md)                         | All `app.*`, `auth.*`, `filesystem.*`, `session.*`, `cache.*`, `queue.*`, `events.*`, `media.*`, `notifications.*` config keys                                     |
| [docs/packages.md](docs/packages.md)                     | Building Ions packages: `extra.ions.providers` zero-config discovery, provider conventions, package commands                                                       |
| [docs/performance.md](docs/performance.md)               | Production caches (`optimize`, route/config/discover), opcache preload, N+1 detector, measured numbers                                                             |
| [docs/worker-mode.md](docs/worker-mode.md)               | Experimental worker mode: `Kernel::resetForRequest()`, `WorkerRunner`, state table, FrankenPHP example                                                             |
| [docs/deploy.md](docs/deploy.md)                         | Deployment: nginx/Apache configs, `public/.htaccess`, PHP-FPM pool notes, TLS-proxy caveat, deploy checklist                                                       |
| [CHANGELOG.md](CHANGELOG.md)                             | What changed in each release                                                                                                                                       |
| [UPGRADE-4.1.md](UPGRADE-4.1.md)                         | Behavior changes and migration guide for 4.0 → 4.1.0                                                                                                               |
| [UPGRADE-4.0.md](UPGRADE-4.0.md)                         | Breaking changes and migration guide for 3.x → 4.0.0                                                                                                               |
| [UPGRADE-3.0.md](UPGRADE-3.0.md)                         | Breaking changes and migration guide for 2.1.x → 3.0.0                                                                                                             |

---

## License

MIT — see [LICENSE.md](LICENSE.md).
