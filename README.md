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

Serve it locally with `php bin/ions serve` (PHP's built-in dev server on
`http://127.0.0.1:8000`; `--host`/`--port` to change — `php -S localhost:8000 -t public`
remains the raw alternative).

### Route (`routes/web.php`)

```php
<?php
use App\Http\Controllers\UsersController;
use Ions\Bundles\Route;

Route::get('/users', UsersController::class . '::index');

Route::prefix('/api/v1')->group(function () {
    Route::post('/users', 'UserController@store')->middleware(['throttle']);
});
```

### Controller (`app/Http/Controllers/UsersController.php`)

```php
<?php
use App\Services\UserService;
use Ions\Foundation\BaseController;
use Ions\Support\Request;
use Ions\View\View;

class UsersController extends BaseController
{
    public function __construct(private readonly UserService $users)  // container-built (4.2)
    {
        parent::__construct();
    }

    public function index(Request $request): View
    {
        // Controller-relative view: renders views/users/index.twig as a 200 response
        return $this->view('index', ['users' => $this->users->all()]);
    }
}
```

Actions are method-injected (the request, route placeholders by name, services by type-hint) and may return a
`View` (`view()` / `$this->view()`), a `Symfony\Component\HttpFoundation\Response`, or any `Ions\Http\Responsable`
(e.g. an API `Resource`) — the framework normalises the return value before sending. An Eloquent-typed parameter named
after a placeholder is route-model-bound (`show(Widget $widget)` on `/widgets/{widget}` — fetched or 404, 4.3). API
controllers extend `Ions\Foundation\ApiController` and return JSON (`Json::ok([...])`). Authorization is one call:
`$this->authorize('update', $post)` in an action, `can('update', $post)` anywhere, `{% if can('update', post) %}` in
Twig ([docs/auth.md](docs/auth.md#authorization-gate--policies)). See [docs/controllers.md](docs/controllers.md).

### Scheduled tasks (`app/Schedule.php`)

```php
<?php
namespace App;

use Ions\Schedule\Scheduler;

class Schedule
{
    public static function boot(Scheduler $schedule): void
    {
        $schedule->command('emails:send')->daily()->withoutOverlapping();
    }
}
```

One crontab line runs everything that is due: `* * * * * cd /path/to/app && php bin/ions schedule:run` — see
[docs/scheduler.md](docs/scheduler.md).

Starting a new app? Copy the [host-app skeleton](docs/skeleton.md) (`skeleton/`) and read
[docs/best-practices.md](docs/best-practices.md) — the opinionated guide to structuring an Ions application.

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
│   ├── Http/              # Controllers (web)
│   └── Models/            # Eloquent models (App\Models, make:model)
├── database/              # Host-root layout (4.4): migrations/, seeders/,
│   │                      #   factories/, schemas/, backups/
│   ├── migrations/
│   ├── seeders/           # Database\Seeders
│   └── factories/         # Database\Factories
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
- **Worker mode (stable)** — `Kernel::resetForRequest()` + `Ions\Runtime\WorkerRunner` for boot-once/handle-many
  runtimes (FrankenPHP/RoadRunner recipes)
- **Middleware pipeline** — `MiddlewareInterface`, `Pipeline`, per-route `->middleware([...])`
- **Default stacks**: web (TrustedHost + SecurityHeaders + CORS + CSRF), api (+ AuthMiddleware)
- **Routing** — `Route::get/post/put/patch/delete/any/match/resource`, fluent `->name()` / `->where()` /
  `->middleware()`, `Route::redirect`/`view`/`fallback` shortcut routes, prefix/group nesting with name + middleware
  prefixes, attribute routing (`#[Route]`), `route()` URL helper
- **Route model binding** — Eloquent-typed, placeholder-named action params resolve to the fetched record
  (`getRouteKeyName()`) or 404; nullable params get null on a miss
- **Trusted proxies** — `app.trusted_proxies` (IPs/CIDRs or `'*'`) with fail-closed header sets (`xff`/`aws-elb`/
  `traefik`/`forwarded`); proxy-aware `isSecure()`, HSTS and `cookie_secure => 'auto'` behind TLS-terminating LBs
- **JWT auth** (`Ions\Security\Jwt`) — access + refresh tokens, revocation deny-list, clock leeway
- **HTTP auth endpoints** — `Ions\Auth\Http\AuthController` (login / refresh / logout / password reset); per-user-bound
  tokens; refresh-token rotation with family reuse detection (replay revokes the whole family) (4.4)
- **TOTP two-factor** (`Ions\Auth\TwoFactor`) — RFC 6238 verifier with drift window, recovery codes, otpauth URI for
  QR provisioning, single-use replay store (4.5)
- **Email verification** (`Ions\Auth\EmailVerification`) — signed links bound to the current email, `VerifiesEmail`
  contract, `verified` middleware, `VerifyEmail` notification, resend throttle (4.5)
- **Pluggable auth** — `UserProvider` contract; `SentinelUserProvider` (default) or `EloquentUserProvider`
- **Authorization** — `Ions\Auth\Gate` abilities + model policies: `allows`/`denies`/`authorize` (403 on deny),
  `can()` helper + Twig `can()` function, `$this->authorize()` in controllers, guest auto-deny
- **Multi-driver filesystem** — `Ions\Filesystem\Storage` / `FilesystemManager`; `local`, `s3`, `ftp`, `sftp`,
  `memory` + custom drivers; `putFile`/`download()`/`url()`/`temporaryUrl()`/listings; `Storage::fake()` also
  intercepts the legacy `IonDisk`/`IonUpload` (unified in 4.3)
- **Session** — `Ions\Session\SessionManager`; `session()` helper; CSRF stored in the session
- **Console** — `bin/ions` runner + `Ions\Console\Kernel`; command discovery; `make:command`, `queue:work`, `serve`
  (PHP dev server), `down`/`up` (maintenance mode)
- **Cron scheduler** — `App\Schedule::boot(Scheduler)` fluent tasks (`->daily()`, `->withoutOverlapping()`, …);
  `schedule:run` / `schedule:list`; `/cron/schedule` web-cron parity
- **Host-app diagnostics** — `ions doctor` (env, APP_KEY, writable `var/`, caches, DB, extensions, security posture);
  `--json` for CI; exits non-zero on critical misconfig
- **Cache / Queue / Events** — `cache()` / `dispatch()` / `event()`+`listen()` helpers; Illuminate-backed providers
- **Queue resilience** — `$tries`/`$backoff` job properties, failed-jobs store (`queue.failed`),
  `queue:failed` / `queue:retry` / `queue:forget` / `queue:flush`
- **Pagination + web form flow** — `$query->paginate(n)` + Twig `pagination()`; session flash, `old()`/`errors()`,
  fluent `redirect()->back()->withErrors()->withInput()` (same-origin `back()` guard)
- **Channel logging** — `Ions\Support\Log` facade over `config/logging.php` (`single`/`daily`/`stderr`/`stack`
  drivers, per-channel levels), secret redaction + per-request id correlation
- **Outbound HTTP client** — `Ions\Support\Http` facade over `symfony/http-client` (retry/timeout/token builder);
  `Http::fake()` for tests
- **Mailables** — `Ions\Mail\Mailable` (build/send/queue with Twig views); `Mail::fake()` FQCN assertions
- **Notifications** — `Ions\Notifications\Notification` with mail + database channels, custom channels, `notify()`;
  `Notifications::fake()`
- **Host-root `database/` layout** — Laravel-standard `database/` tree (`migrations`/`seeders`/`factories`/`schemas`/
  `backups`) with precedence over legacy `{app|src}/Database`; `Database\Factories`/`Database\Seeders` namespaces;
  `make:model` → `app/Models` (`App\Models`, `--factory` flag); `ions doctor` dual-directory warning (4.4)
- **Model factories** — `Ions\Database\Factory` (`make`/`create`/`count`/`state`), `HasIonsFactory` (explicit
  `$factory` → `Database\Factories` → `{ModelNs}\Factories` resolution), Faker integration
- **Testing kit** — `Ions\Testing\TestCase` + `TestResponse` (verb helpers, `actingAs()` real JWT) plus
  Queue/Event/Storage/Mail/Notifications/Http fakes
- **N+1 query detector** — debug-only repeated-pattern warnings to `var/logs/performance.log`
- **ORM strict mode (debug)** — lazy-load and silently-discarded-fill violations throw under `APP_DEBUG`;
  `database.strict => false` escape hatch
- **Health endpoint** — built-in `/up` liveness probe + token-gated `?checks=1` doctor JSON; `Cache-Control: no-store`
- **Debugging UX** — interactive debug page (clickable frames, server-side syntax highlighting, redacted
  request/headers/params/cookies/session/context tabs, copy-as-markdown, open-in-editor) + expandable debug toolbar
  (query/request/timing panels, query cap) + branded production error pages (400–503, host-template override); shared
  `Ions\Http\Ui` design system, dependency-free + self-contained + dev-only gated (14.6)
- **Maintenance mode** — `ions down [--secret] [--retry]` / `ions up`; themeable 503 (`errors/503.twig`), HMAC-bound
  bypass cookie, `/up` stays reachable
- **PSR-15 adapter** — `Psr15Adapter` runs any PSR-15 middleware in the Ions pipeline (nyholm/psr7 + Symfony bridge)
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
- **Response cache** — opt-in full-page cache for anonymous GET 200s (`cache.response` alias); ETag/`304`,
  `Vary`, TTL, `X-Ions-Cache` HIT/MISS, never caches auth/session, debug bypass; `ions cache:clear-responses`
  (≈10–12× faster cached vs live render in `bench/bench.php`)
- **Exception handler** — `Ions\Http\ExceptionHandler`; JSON for API (incl. 422 validation), HTML for web with
  host-themeable `views/errors/{status}.twig` pages (4.3); web validation failures redirect back with errors + input;
  safe in production
- **Generators** — `make:model` (`app/Models`, `--factory`), `make:middleware`, `make:service-provider`,
  `make:command`, `make:resource`, `make:request`, `make:job`, `make:event`, `make:listener`, `make:test`,
  `make:factory`, `make:seeder`
- **Host-app skeleton** — `skeleton/`: a minimal bootable host layout with the 4.1 secure defaults pre-filled
- **Debug error page** — interactive: clickable frames, highlighted source, redacted request tabs, copy/open-in-editor
  (`APP_DEBUG=true` only) — see *Debugging UX* above
- **IDE support** — `.phpstorm.meta.php` ships with the package, so PhpStorm infers concrete types for `app('id')` and
  container `get()`/`make()` lookups automatically
- **CI** — PHPStan (level 5 full / level 8 core), PHP-CS-Fixer, Rector (Laravel 12), Pest 3 (PHP 8.3 + 8.4 × SQLite +
  MySQL 8)

---

## Documentation

| Document                                                 | Contents                                                                                                                                                           |
|----------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| [docs/best-practices.md](docs/best-practices.md)         | The opinionated guide: `app/` structure, thin controllers, providers/DI, events vs jobs vs notifications, testing, security & performance checklists               |
| [docs/skeleton.md](docs/skeleton.md)                     | Host-app skeleton (`skeleton/`): layout, quick-start, secure defaults                                                                                              |
| [docs/testing.md](docs/testing.md)                       | Host-app test kit: `Ions\Testing\TestCase`, verb helpers, `actingAs()` (real JWT), `TestResponse` assertions                                                       |
| [docs/factories.md](docs/factories.md)                   | Minimal model factories: `Ions\Database\Factory`, `make()`/`create()`/`count()`/`state()`, `HasIonsFactory`, `make:factory`                                        |
| [docs/lifecycle.md](docs/lifecycle.md)                   | Boot sequence, request pipeline, response dispatch                                                                                                                 |
| [docs/routing.md](docs/routing.md)                       | Route registration, prefixes, groups, middleware, attributes                                                                                                       |
| [docs/controllers.md](docs/controllers.md)               | Controller lifecycle (legacy + `boot`/`beforeAction`/`afterAction`), constructor/action DI, `middleware()`, return normalization                                   |
| [docs/middleware.md](docs/middleware.md)                 | `MiddlewareInterface`, pipeline, default stacks, writing middleware                                                                                                |
| [docs/views.md](docs/views.md)                           | Twig views: `view()` renderable returns, namespaced roots (`app.twig.paths`), controller-relative `$this->view()`, `pagination()`                                  |
| [docs/forms.md](docs/forms.md)                           | Web form flow: flash data, `old()`/`errors()`, fluent `redirect()`/`back()`, FormRequest web redirects, `app.forms.dont_flash`                                     |
| [docs/assets.md](docs/assets.md)                         | Frontend assets: `install:vue` (Vue 3 + Vite, hot-file dev mode), `install:assets` (no-build starters), `vite()`/`asset()` Twig functions                          |
| [docs/auth.md](docs/auth.md)                             | UserProvider, JWT, AuthController endpoints, AuthMiddleware, rate limiting, CSRF                                                                                   |
| [docs/two-factor.md](docs/two-factor.md)                 | TOTP two-factor (`Ions\Auth\TwoFactor`): RFC 6238 verifier, drift window, otpauth URI → QR, recovery codes, replay store, encrypt-at-rest, login challenge        |
| [docs/email-verification.md](docs/email-verification.md) | Email verification (`Ions\Auth\EmailVerification`): signed links bound to the current email, `VerifiesEmail` contract, `verified` middleware, `VerifyEmail` notification, resend throttle |
| [docs/filesystem.md](docs/filesystem.md)                 | Multi-driver disks, `Storage`, `FilesystemManager`, uploads                                                                                                        |
| [docs/session.md](docs/session.md)                       | `SessionManager`, `session()`, `StartSessionMiddleware`, CSRF                                                                                                      |
| [docs/cache-queue-events.md](docs/cache-queue-events.md) | `cache()` / `dispatch()` / `event()`+`listen()`, jobs, retries/backoff, failed jobs, `queue:work` / `queue:retry`                                                  |
| [docs/logging.md](docs/logging.md)                       | Channel logging: `Log` facade, `config/logging.php` drivers (`single`/`daily`/`stderr`/`stack`), request-id correlation, redaction, `Logs::create()` legacy        |
| [docs/mail.md](docs/mail.md)                             | `Mailable` classes (build/send/queue, Twig views), `Mail` facade, `Mail::fake()` FQCN assertions, `newMailerDsn()`                                                 |
| [docs/notifications.md](docs/notifications.md)           | `Notification` classes (`via()`/`toMail()`/`toDatabase()`), mail recipient routing, notifications table stub, custom channels, `notify()`, `Notifications::fake()` |
| [docs/console.md](docs/console.md)                       | Console Kernel, `bin/ions`, command discovery, `make:command`, `doctor` diagnostics                                                                                |
| [docs/scheduler.md](docs/scheduler.md)                   | Cron scheduler: `App\Schedule::boot(Scheduler)`, frequencies, `withoutOverlapping()`, `schedule:run`/`schedule:list`, web-cron                                     |
| [docs/resources.md](docs/resources.md)                   | API resources, collections, form requests, `openapi:generate`                                                                                                      |
| [docs/media.md](docs/media.md)                           | Image processing over `intervention/image` v3                                                                                                                      |
| [docs/http-client.md](docs/http-client.md)               | Outbound HTTP: `Http` facade over `symfony/http-client`, response wrapper, `Http::fake()`                                                                          |
| [docs/security.md](docs/security.md)                     | `Encrypter` (sodium AEAD), `UrlSigner`, `signedRoute()`/`signedUrl()`, `signed` middleware                                                                         |
| [docs/security-audit-bundles.md](docs/security-audit-bundles.md) | Legacy `Bundles/` security audit: upload/disk path-traversal containment in `Path`, SVG/HTML/JS/XML deny-list, fail-closed upload content validation, `IonDisk::getSignedUrl()` presigning |
| [docs/response-cache.md](docs/response-cache.md)         | Opt-in HTTP response caching: `ResponseCache`, `cache.response` middleware, ETag/304 revalidation, auth/session-safe rules, `cache:clear-responses`                |
| [docs/errors-and-debugging.md](docs/errors-and-debugging.md) | Debugging UX: interactive debug page (frames/tabs/copy/open-in-editor, redaction), expandable debug toolbar (query/request/timing panels, query cap), branded production error pages + host overrides, `app.debug.editor` / `app.debug_toolbar` |
| [docs/config.md](docs/config.md)                         | All `app.*`, `auth.*`, `filesystem.*`, `session.*`, `cache.*`, `logging.*`, `queue.*`, `events.*`, `media.*`, `notifications.*` config keys                        |
| [docs/packages.md](docs/packages.md)                     | Building Ions packages: `extra.ions.providers` zero-config discovery, provider conventions, package commands                                                       |
| [docs/performance.md](docs/performance.md)               | Production caches (`optimize`, route/config/discover), opcache preload, N+1 detector, measured numbers                                                             |
| [docs/worker-mode.md](docs/worker-mode.md)               | Worker mode (stable): `Kernel::resetForRequest()`, `WorkerRunner`, per-request state isolation matrix, FrankenPHP + RoadRunner recipes                             |
| [docs/deploy.md](docs/deploy.md)                         | Deployment: nginx/Apache configs, `public/.htaccess`, PHP-FPM pool notes, TLS-proxy caveat, deploy checklist                                                       |
| [CHANGELOG.md](CHANGELOG.md)                             | What changed in each release                                                                                                                                       |
| [UPGRADE-4.5.md](UPGRADE-4.5.md)                         | Behavior changes and migration guide for 4.4 → 4.5.0                                                                                                               |
| [UPGRADE-4.4.md](UPGRADE-4.4.md)                         | Behavior changes and migration guide for 4.3 → 4.4.0                                                                                                               |
| [UPGRADE-4.3.md](UPGRADE-4.3.md)                         | Behavior changes and migration guide for 4.2 → 4.3.0                                                                                                               |
| [UPGRADE-4.2.md](UPGRADE-4.2.md)                         | Behavior changes and migration guide for 4.1 → 4.2.0                                                                                                               |
| [UPGRADE-4.1.md](UPGRADE-4.1.md)                         | Behavior changes and migration guide for 4.0 → 4.1.0                                                                                                               |
| [UPGRADE-4.0.md](UPGRADE-4.0.md)                         | Breaking changes and migration guide for 3.x → 4.0.0                                                                                                               |
| [UPGRADE-3.0.md](UPGRADE-3.0.md)                         | Breaking changes and migration guide for 2.1.x → 3.0.0                                                                                                             |

---

## Reference application

[`examples/taskflow/`](examples/taskflow/) is a complete, working host
application — a project/task tracker — that exercises every framework
subsystem once and ships its own Pest suite as living documentation. It is
path-linked to this working-tree core (`repositories: path → ../../`,
symlinked), so it runs against the exact code in this repo and a dedicated
CI `example` job keeps it green.

It dogfoods the public API end-to-end: registration + signed email
verification, TOTP two-factor, session + JWT auth with Gate policies,
project/task CRUD with route-model binding, FormRequests, pagination and
`IonUpload` attachments, queued jobs, notifications (mail + database),
mailables, the cron scheduler, signed/expiring share links, response
caching, encryption-at-rest, and the outbound HTTP client. See its
[README](examples/taskflow/README.md) for the full feature → subsystem map
and `tests/FeatureJourneyTest.php` for the whole journey in one narrative.

---

## License

MIT — see [LICENSE.md](LICENSE.md).
