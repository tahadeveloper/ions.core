# Building Ions Packages

How to ship a reusable composer package for the Ions framework with a **zero-config install story**: `composer require acme/ions-stripe` and the package's services are bound on the next boot — no host config edits.

---

## Provider auto-discovery (`extra.ions.providers`)

Declare your package's service providers in its `composer.json` under `extra.ions.providers` (an array of FQCNs):

```json
{
    "name": "acme/ions-stripe",
    "type": "library",
    "require": { "ionzile/core": "^4.0" },
    "autoload": { "psr-4": { "Acme\\Stripe\\": "src/" } },
    "extra": {
        "ions": {
            "providers": [ "Acme\\Stripe\\StripeProvider" ]
        }
    }
}
```

At boot, `Ions\Foundation\Discovery` reads `vendor/composer/installed.json` (the only runtime composer metadata that carries `extra` — `installed.php` / `Composer\InstalledVersions` do not expose it) **once per process** (memoized) and registers every declared provider that is a concrete `Ions\Container\ServiceProvider` subclass. Entries that don't exist, aren't providers, or are abstract are silently skipped — a bad declaration never breaks the host's boot.

Order: framework defaults → **package providers** → host `{src|app}/Providers/` (host last, so the host always wins). The merged list is de-duplicated, first occurrence wins.

Discovery is bypassed when the host sets `app.providers` (explicit full control) and disabled by `app.discovery => false` — see [config.md](config.md#appproviders). A host can also opt out of a single package via `app.dont_discover => ['acme/ions-stripe']` (exact package-name match, see [config.md](config.md#appdont_discover)). Package authors should document that hosts using an explicit `app.providers` list must add the package's provider(s) themselves.

> **Security note:** `composer require` is code running at boot — discovered providers register and boot on every request. Hosts should review the `extra.ions.providers` of packages they install; the escape hatches are `app.dont_discover` (per package), `app.discovery => false` (no scans) and an explicit `app.providers` list (full control).

In production hosts typically freeze the discovered list with `ions discover:cache` (part of `ions optimize`); remind users to re-run it after `composer require`/`update` so your package's providers enter the cache — see [performance.md](performance.md).

## Writing the provider

```php
<?php

declare(strict_types=1);

namespace Acme\Stripe;

use Ions\Container\ServiceProvider;

class StripeProvider extends ServiceProvider
{
    /** Bind services. Runs for ALL providers before any boot(). */
    public function register(): void
    {
        $this->container->singleton('stripe', function () {
            return new StripeClient((string) config('stripe.secret'));
        });
    }

    /** Optional. May depend on other providers' bindings. */
    public function boot(): void
    {
        // e.g. register event listeners:
        // listen(\Ions\Events\RequestHandled::class, MyListener::class);
    }
}
```

Conventions:

- `register()` must only **bind** (no side effects, no resolving other services) — every provider's `register()` runs before any `boot()`.
- `boot()` is for side-effecting startup and may resolve anything bound during the register pass.
- Read package settings via `config('yourpackage.*')` and document the keys; hosts create `config/yourpackage.php` to override defaults.

## Registering console commands from a package

There is no composer-extra hook for commands (host commands are auto-discovered from `{src|app}/Commands` — see [console.md](console.md)). Packages register commands through their provider's `register()` by appending to `config('console.commands')`, which the Console Kernel reads **after** providers have bootstrapped:

```php
public function register(): void
{
    config()->set('console.commands', array_merge(
        (array) config('console.commands', []),
        [\Acme\Stripe\Commands\SyncCommand::class],
    ));
}
```

This works today with no extra machinery: `Ions\Console\Kernel::boot()` runs `Foundation\Kernel::boot()` (providers register + boot) first, then collects `config('console.commands')` plus the host `Commands/` scan. Non-command or abstract entries are skipped.

## Event listeners

There is deliberately **no listener discovery**: `config('events.listen')` is the host-side convention (see [cache-queue-events.md](cache-queue-events.md)). A package that needs to listen subscribes imperatively in its provider's `boot()` via the `listen()` helper (or the `events` dispatcher binding).

## Testing your package

Use the discovery test seam to boot a fixture host with your package's metadata injected — no real `vendor/` install needed:

```php
use Ions\Foundation\{Discovery, Kernel};

Kernel::resetForTesting(); // also resets Discovery
Discovery::useMetadata([
    json_decode(file_get_contents(__DIR__ . '/../composer.json'), true),
]);
Kernel::boot(__DIR__ . '/fixtures/host-app');

expect(Kernel::app()->has('stripe'))->toBeTrue();
```

`Discovery::reset()` (called by `Kernel::resetForTesting()`) clears both the injected metadata and the per-process memo.
