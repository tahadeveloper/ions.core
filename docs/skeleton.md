# Host-App Skeleton

`skeleton/` is the reference host application for the framework — a minimal,
production-sane starting point that will be split into the standalone
`ionzile/app` package at release (`composer create-project ionzile/app`).
Until then, copy it:

```bash
cp -R skeleton my-app && cd my-app
composer install
cp .env.example .env          # then set APP_KEY (64-char hex):
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
php -S localhost:8000 -t public
```

`http://localhost:8000/` renders the Twig welcome page; `GET /api/ping`
returns the framework's JSON envelope.

## Layout

```
skeleton/
├── public/
│   ├── index.php          # Front controller: Kernel::boot() + Kernel::run()
│   ├── uploads/           # Local filesystem disk root
│   └── lang/              # Translation files (public/lang/{locale}/web.php)
├── bin/ions               # Console entry: Ions\Console\Kernel::boot()->run()
├── config/                # app / auth / cache / database / filesystem /
│                          # queue / session / events / console
├── routes/
│   ├── web.php            # '/' -> App\Http\Controllers\HomeController::index
│   └── api.php            # '/api/ping' sample + commented JWT auth surface
├── app/                   # App\ (PSR-4)
│   ├── Booting.php        # Optional Kernel::boot() hook
│   ├── Schedule.php       # Scheduled tasks: boot(Scheduler) (docs/scheduler.md)
│   ├── Http/Controllers/  # Web controllers (extend Ions\Foundation\BaseController)
│   └── Commands/          # Auto-discovered console commands
├── views/home/index.twig  # Twig templates (app.twig.source; HomeController -> views/home/)
├── var/                   # Writable: cache/, logs/, templates/ (compiled Twig)
└── .env.example
```

## Secure defaults (4.1)

The skeleton deliberately ships the 4.1 security posture **by omission** —
see [UPGRADE-4.1.md](../UPGRADE-4.1.md):

- **Session cookies**: `cookie_secure` / `cookie_httponly` / `samesite=lax`
  defaults are not overridden. For plain-HTTP local dev, set
  `'cookie_secure' => 'auto'` (or `false`) in `config/session.php`.
- **CORS**: `app.cors.origins = []` (deny-by-default; no CORS headers).
- **AuthMiddleware**: every `/api/*` route requires a Bearer token except the
  paths in `app.auth.public_paths` (login, refresh, and the `/api/ping` sample).
- HSTS, Permissions-Policy and upload magic-byte validation stay at the
  framework defaults.

## IDE support

PhpStorm completion and type inference for container lookups (`app('queue')`,
`Kernel::app()->get('cache')`, `->make(...)`) come from the `.phpstorm.meta.php`
that ships at the root of `ionzile/core` — PhpStorm reads it from `vendor/`
automatically, so the skeleton needs no IDE configuration of its own.

## Testing the skeleton (in this repo)

`tests/Feature/SkeletonTest.php` boots the kernel against `skeleton/`
(`App\` is autoloaded by the test itself, not by this repo's composer.json),
copies `.env.example` to a temporary `.env` per test, and asserts `/` and
`/api/ping` end-to-end via `Kernel::handle()`.
