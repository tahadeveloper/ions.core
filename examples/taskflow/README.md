# Taskflow — Ions reference application

Taskflow is the in-repo reference application for the
[Ions PHP framework](https://github.com/tahadeveloper/ions.core) (`ionzile/core`).
It is a small project &amp; task tracker that exercises every Ions subsystem
once — it doubles as living documentation and an integration test of the
framework's public API surface, run in CI against the working-tree core.

Unlike a real host app (which requires a published `ionzile/core ^4.x`), this
example **path-links the local core**: its `composer.json` declares a `path`
repository to `../../` with `symlink: true` and requires `ionzile/core: @dev`.
`composer install` therefore symlinks the repo root in as the installed core,
so the suite always runs against the current working tree.

## Quick start

```bash
cd examples/taskflow

# Install dependencies — this symlinks ../../ (the local core) into vendor/.
composer install

cp .env.example .env
# Set APP_KEY in .env (64-char hex):
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

# Sanity-check the setup (APP_KEY, writable var/, DB, security posture):
php bin/ions doctor

# Create the SQLite database (default DB_CONNECTION=sqlite):
php bin/ions migrate

php bin/ions serve     # dev server on http://127.0.0.1:8000 (--host/--port)
```

(`serve` wraps PHP's built-in server — `php -S localhost:8000 -t public`
works just as well.)

Open <http://localhost:8000> for the welcome page;
`curl localhost:8000/api/ping` returns `{"status":"success","data":{"message":"pong"}}`;
`curl localhost:8000/up` is the health probe.

> Local HTTP dev: session cookies are `Secure` by default (4.1). If you need
> session/CSRF over plain `http://localhost`, set `'cookie_secure' => 'auto'`
> in `config/session.php`.

## Running the tests

The example ships its own Pest suite, run from inside this directory against
the symlinked core:

```bash
cd examples/taskflow
composer install
vendor/bin/pest
```

Tests extend `Ions\Testing\TestCase` (via `Tests\TaskflowTestCase`, whose
`basePath()` points at this directory) and boot a fresh kernel in-process per
test. No real `.env` is committed — the bootstrap materialises one from
`.env.example` for the duration of the run and forces a SQLite `:memory:`-style
throwaway environment (`SESSION_DRIVER=array`, `APP_DEBUG=true`, a dummy
`APP_KEY`).

## Where things live

| Path | Purpose |
| --- | --- |
| `public/index.php` | Front controller (`Kernel::boot()` + `run()`). |
| `bin/ions` | Console entry point (`migrate`, `serve`, `doctor`, …). |
| `config/` | Per-file config arrays (secure defaults; SQLite by default). |
| `app/Http/Controllers/` | Web controllers (`HomeController` welcome page). |
| `app/Http/Api/` | JSON API controllers (added in later sub-phases). |
| `routes/web.php`, `routes/api.php` | Route definitions (`/`, `/api/ping`). |
| `views/` | Twig templates. |
| `database/` | `migrations/`, `schemas/`, `factories/`, `seeders/`. |
| `tests/` | Pest suite (`SmokeTest` boot gate). |

> The full feature → subsystem coverage map (auth, CRUD, uploads, jobs, mail,
> scheduler, signed links, response cache, encryption) lands with the
> coverage suite in a later sub-phase (13.7).
