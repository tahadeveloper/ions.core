# Ions Application Skeleton

A minimal host application for the [Ions PHP framework](https://github.com/tahadeveloper/ions.core) (`ionzile/core`).

## Quick start

```bash
# Until the skeleton is published as ionzile/app
# (then: composer create-project ionzile/app my-app), copy this directory:
cp -R skeleton my-app && cd my-app

composer install
cp .env.example .env
# set APP_KEY in .env (64-char hex):
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

php -S localhost:8000 -t public
```

Open <http://localhost:8000> — you should see the welcome page. `curl localhost:8000/api/ping` returns JSON.

> Local HTTP dev: session cookies are `Secure` by default (4.1). If you need
> session/CSRF over plain `http://localhost`, set `'cookie_secure' => 'auto'`
> in `config/session.php`.

## Where things live

| Path | Purpose |
|---|---|
| `public/index.php` | Front controller (`Kernel::boot()` + `Kernel::run()`) |
| `bin/ions` | Console (`php bin/ions list`, `make:*`, `migrate`, `queue:work`, `optimize`) |
| `config/` | One PHP file per namespace: `app.php` → `config('app.*')`, … |
| `routes/web.php`, `routes/api.php` | Route definitions (`Ions\Bundles\Route`) |
| `src/` | Application code (`App\` PSR-4): controllers in `src/Http/Controllers`, API controllers in `src/Http/Api`, commands in `src/Commands` |
| `views/` | Twig templates |
| `public/uploads`, `public/lang` | Uploads disk root, translation files |
| `var/` | Writable: `cache/`, `logs/`, `templates/` (compiled Twig) |

## Production notes

- Set `APP_DEBUG=false` and run `php bin/ions optimize` (route + config caches).
- CORS is deny-by-default; configure `app.cors.origins` when serving cross-origin traffic.
- Review `UPGRADE-4.1.md` in `ionzile/core` for the 4.1 security defaults this skeleton embraces.
