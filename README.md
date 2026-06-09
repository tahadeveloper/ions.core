# Ions Framework — Core

[![CI](https://github.com/tahadeveloper/ions.core/actions/workflows/ci.yml/badge.svg)](https://github.com/tahadeveloper/ions.core/actions/workflows/ci.yml)

A lightweight PHP 8.2+ framework built on **Symfony** HTTP and routing components and **Illuminate** database, cache, and container — providing a structured, secure foundation without the full overhead of a monolithic framework.

---

## Requirements

- PHP 8.2 or 8.3
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

`Kernel::boot()` loads `.env`, boots the container, registers service providers, and loads routes. `Kernel::run()` handles the request and sends the response. Existing front controllers that call `Kernel::make()` continue to work — it is a thin BC shim around `run()`.

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

Controllers may return a `Symfony\Component\HttpFoundation\Response` (including `JsonResponse`) or any object implementing `Ions\Http\Responsable`. The framework normalises the return value before sending.

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
├── src/                   # Application source (or app/)
│   └── Http/              # Controllers (web)
├── views/
│   └── default/           # Twig templates
├── var/
│   └── cache/             # Twig, rate-limit, revocation caches
└── .env
```

The framework resolves application paths relative to the host-app root (five directory levels above `vendor/`). Use `Ions\Bundles\Path` helpers (`Path::src()`, `Path::config()`, `Path::views()`, etc.) to reference locations portably. Both `src/` and `app/` directory names are supported.

---

## Features

- **PSR-11 container** (`Ions\Container\Container`) with `bind`, `singleton`, `make`
- **Service providers** (`Ions\Container\ServiceProvider`) with two-pass bootstrap
- **Middleware pipeline** — `MiddlewareInterface`, `Pipeline`, per-route `->middleware([...])`
- **Default stacks**: web (TrustedHost + SecurityHeaders + CORS + CSRF), api (+ AuthMiddleware)
- **Routing** — `Route::get/post/put/patch/delete/any/match/resource`, prefix/group nesting, attribute routing (`#[Route]`)
- **JWT auth** (`Ions\Security\Jwt`) — access + refresh tokens, revocation deny-list, clock leeway
- **Pluggable auth** — `UserProvider` contract; `SentinelUserProvider` (default) or `EloquentUserProvider`
- **JSON helpers** — `Json::ok()` / `Json::error()`
- **Twig views** — `Ions\View\ViewFactory`; bound as `view` in the container
- **Security headers** on every response; configurable CSP
- **CSRF enforcement** on web routes (opt-out via `app.csrf.enabled = false`)
- **Upload validation** — extension allow-list + hard-coded executable deny-list
- **Rate limiting** — `RateLimitMiddleware` / `throttle` alias, 429 + `Retry-After`
- **Exception handler** — `Ions\Http\ExceptionHandler`; JSON for API, HTML for web; safe in production
- **Generators** — `make:middleware`, `make:service-provider`
- **CI** — PHPStan (level 4 full / level 8 core), PHP-CS-Fixer, Rector, Pest 3 (PHP 8.2 + 8.3 × SQLite + MySQL 8)

---

## Documentation

| Document | Contents |
|---|---|
| [docs/lifecycle.md](docs/lifecycle.md) | Boot sequence, request pipeline, response dispatch |
| [docs/routing.md](docs/routing.md) | Route registration, prefixes, groups, middleware, attributes |
| [docs/middleware.md](docs/middleware.md) | `MiddlewareInterface`, pipeline, default stacks, writing middleware |
| [docs/auth.md](docs/auth.md) | UserProvider, JWT, AuthMiddleware, rate limiting, CSRF |
| [docs/config.md](docs/config.md) | All `app.*` and `auth.*` config keys |
| [CHANGELOG.md](CHANGELOG.md) | What changed in each release |
| [UPGRADE-2.0.md](UPGRADE-2.0.md) | Breaking changes and migration guide for v1 → v2 |

---

## License

MIT — see [LICENSE.md](LICENSE.md).
