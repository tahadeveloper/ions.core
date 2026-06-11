# Ions Application Skeleton

A minimal host application for the [Ions PHP framework](https://github.com/tahadeveloper/ions.core) (`ionzile/core`).

## Quick start

```bash
# Until the skeleton is published as ionzile/app
# (then: composer create-project ionzile/app my-app), copy this directory:
cp -R skeleton my-app && cd my-app
```

```bash
composer install
cp .env.example .env
# set APP_KEY in .env (64-char hex):
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

# sanity-check the setup (APP_KEY, writable var/, DB, security posture):
php bin/ions doctor

php bin/ions serve     # dev server on http://127.0.0.1:8000 (--host/--port)
```

(`serve` wraps PHP's built-in server — `php -S localhost:8000 -t public`
works just as well if you prefer the raw command.)

Open <http://localhost:8000> — you should see the welcome page. `curl localhost:8000/api/ping` returns JSON.

Serving behind nginx or Apache? `public/.htaccess` ships the Apache rewrite;
for the full nginx/Apache configs, PHP-FPM notes and the deploy checklist see
`docs/deploy.md` in `ionzile/core`.

> Local HTTP dev: session cookies are `Secure` by default (4.1). If you need
> session/CSRF over plain `http://localhost`, set `'cookie_secure' => 'auto'`
> in `config/session.php`.

## Where things live

| Path | Purpose |
|---|---|
| `public/index.php` | Front controller (`Kernel::boot()` + `Kernel::run()`) |
| `bin/ions` | Console (`php bin/ions list`, `make:*`, `migrate`, `queue:work`, `optimize`, `doctor`) |
| `config/` | One PHP file per namespace: `app.php` → `config('app.*')`, … |
| `routes/web.php`, `routes/api.php` | Route definitions (`Ions\Bundles\Route`) |
| `app/` | Application code (`App\` PSR-4): controllers in `app/Http/Controllers`, API controllers in `app/Http/Api`, commands in `app/Commands`, service providers in `app/Providers` (auto-discovered — no `app.providers` entry needed; see `docs/config.md` in `ionzile/core`) |
| `views/` | Twig templates |
| `public/uploads`, `public/lang` | Uploads disk root, translation files |
| `var/` | Writable: `cache/`, `logs/`, `templates/` (compiled Twig) |

Generators: `php bin/ions make:command|make:middleware|make:service-provider|make:resource|make:request|make:job|make:event|make:listener|make:test <Name>` scaffold the corresponding class into `app/` (or `tests/` for `make:test`) — see `docs/console.md` in `ionzile/core`.

## Frontend assets

The skeleton ships no frontend tooling — pick a scaffold when you need one:

- `php bin/ions install:vue` — Vue 3 + Vite (dev server with HMR, hashed
  builds in `public/build/`, loaded via the `vite()` Twig function).
- `php bin/ions install:assets` — plain CSS/JS starters in `public/assets/`,
  linked via `asset()` with filemtime cache-busting. No node required.

See `docs/assets.md` in `ionzile/core`.

## Testing

The skeleton ships runnable test scaffolding: `pestphp/pest` in `require-dev`,
a `phpunit.xml` pointing at `tests/`, and `tests/ExampleTest.php`. Run the
suite with `vendor/bin/pest` (or `composer test`).

The framework ships a host-app test kit: subclass `Ions\Testing\TestCase`,
point `$basePath` at this directory, and drive the full HTTP stack in-process
(no web server). See `docs/testing.md` in `ionzile/core` for the full guide.

```php
final class PingTest extends \Ions\Testing\TestCase
{
    protected string $basePath = __DIR__ . '/..';   // app root (from tests/)

    public function test_ping(): void
    {
        $this->get('/api/ping')
            ->assertOk()
            ->assertJsonPath('data.message', 'pong');
    }
}
```

`actingAs($userIdOrUser)` issues a real JWT for protected `/api` routes —
it requires `APP_KEY` (≥ 32 bytes) in the `.env` used by your tests.

Test fakes ship with the kit: `Queue::fake()`, `Event::fake()`,
`Storage::fake()` and `Mail::fake()` swap the real service for a recorder
with assertion helpers (`assertDispatched`, `assertFired`, `assertStored`,
`assertSent`, …) — see the Fakes section of `docs/testing.md`.

## Production notes

- Set `APP_DEBUG=false` and run `php bin/ions optimize` (route + config caches).
- CORS is deny-by-default; configure `app.cors.origins` when serving cross-origin traffic.
- Review `UPGRADE-4.1.md` in `ionzile/core` for the 4.1 security defaults this skeleton embraces.
- Growing the app? `docs/best-practices.md` in `ionzile/core` is the opinionated structure guide this layout follows.
