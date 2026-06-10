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

> **Pre-release note:** `composer.json` requires `ionzile/core:^4.1`, which is
> not yet tagged on Packagist (only 4.0.0 is). Until 4.1.0 is released,
> `composer install` will fail to resolve it — install from the VCS repository
> instead:
>
> ```bash
> composer config repositories.ions vcs https://github.com/tahadeveloper/ions.core
> composer require "ionzile/core:dev-main"
> ```

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

Generators: `php bin/ions make:command|make:middleware|make:service-provider|make:resource|make:request|make:job|make:event|make:listener|make:test <Name>` scaffold the corresponding class into `src/` (or `tests/` for `make:test`) — see `docs/console.md` in `ionzile/core`.

## Testing

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
