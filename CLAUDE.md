# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`ionzile/core` (namespace `Ions\`, PSR-4 from `src/`) is the **core library of the Ions PHP framework** — not an application. It is installed as a Composer dependency into a host application. The framework stitches together Symfony components (Routing, Config, Mailer, Translation, CSRF, YAML) with Laravel's Illuminate packages (Database/Eloquent, Validation, Cache, Console, Filesystem) plus Cartalyst Sentinel for auth, Twig/Smarty for views, and optionally RedBeanPHP. PHP 8.2+ required.

## Commands

```bash
composer install              # install deps
vendor/bin/pest               # run the full test suite (Pest, configured via phpunit.xml)
vendor/bin/pest tests/ExampleTest.php   # run a single test file
vendor/bin/pest --filter 'path'         # run a single test by name
vendor/bin/phpunit            # PHPUnit directly (Pest runs on top of it)
```

Tests live in `tests/`, match `*Test.php`, and use Pest's `test()`/`expect()` syntax. `tests/Pest.php` is the Pest bootstrap (custom expectations/helpers go there). There is no lint/static-analysis config checked in.

## The host-application layout (critical context)

This library resolves paths **relative to the host app**, which lives **5 directories up** from `src/Foundation/` and `src/Bundles/` (see `Path::$environmentPath` and `Kernel::$environmentPath`). All filesystem access goes through `Ions\Bundles\Path`, which expects this host structure:

- `config/` — PHP config files, each returning an array; loaded by filename into `Kernel::config()` (e.g. `config/app.php` → `config('app.*')`).
- `src/` **or** `app/` — host application code. **`Path` checks for `src/` first and falls back to `app/`** for newer projects. Controllers live in `{src|app}/Http/Controllers`, API endpoints in `{src|app}/Http/Api`, super-admin in `{src|app}/Http/super`, migrations/seeders in `{src|app}/Database`.
- `routes/` — `web.php`/`web.yaml` and `api.php`/`api.yaml` route definitions.
- `views/`, `public/` (with `public/uploads`, `public/lang`), `var/` (`cache/`, `logs/`, `templates/`).
- `.env` at the host root (loaded via vlucas/phpdotenv; `Kernel::$envName` defaults to `.env`).

When editing path logic, **always preserve the `src/` → `app/` fallback** in `Path::src/api/database`.

## Architecture

### Boot & request lifecycle (`src/Foundation/Kernel.php`)
`Kernel` is the static heart of the framework (extends `Singleton`). The host's front controller calls:

1. `Kernel::boot()` — loads `.env`, builds the Illuminate `Container` + Facade root (registers `filesystem`/`files`), reads every file in `config/` into a `Config` object, includes `src/helpers.php`, calls the host's `App\Booting::boot()` if it exists, sets timezone from `TIME_ZONE`, runs `preloads` from `app.preloads` config.
2. `Kernel::make($namespace)` — routes and dispatches the request:
   - First URL segment `api` selects the `api` route file + `Api\` namespace + JSON error rendering (`errorDebugApi`/Whoops); otherwise `web` + HTML errors (`errorDebug`/Spatie Ignition when `APP_DEBUG=true`).
   - `captureRoute()` loads routes from `routes/{web|api}.php` if present, else `.yaml`, **then merges attribute routes** discovered in `Http/` (web) or `Http/Api` (api) via `AttributeRouteControllerLoader`, plus a `/cron/schedule` route → `App\Schedule::boot`.
   - **Host-header security gate**: the request `host` + `APP_FOLDER` must equal `APP_URL` (protocol stripped) or it throws `EncryptException`. Keep `APP_URL`/`APP_FOLDER` consistent when debugging "App host does not exist" errors.
   - Controller string supports `Controller::method`, `Controller@method`, or closure `_controller`. Namespacing: `super`/`api`/`Api` (and `app.needles`) controllers get `$namespace`; everything else gets `$namespace . 'Controllers\\'`.

### Controller lifecycle hooks
Dispatched controllers (`instanceTheController`) get these called in order if they exist: `_initState` → `_loadInit` → `_loadedState` → action (via `callAction` or direct method) → `_endState`, each receiving the `Request`. `Foundation\BaseController` (web) and `Foundation\ApiController` (api) are the abstract bases implementing the `BluePrint` interface; both call `RegisterDB::boot()` in their constructor. `BaseController` wires up Twig+Smarty and Localization in `_loadInit`; `ApiController` enforces `isAuthorized()` and parses request inputs.

### Routing (two styles, both supported)
- **`Ions\Bundles\Route`** — fluent static facade (`Route::get/post/put/...`, `Route::resource`, `Route::prefix(...)->group(...)`). Adds to `Kernel::RouteCollection()`. Used in `routes/*.php`. (`MRoute` is an older variant; prefer `Route`.)
- **Attribute routing** — PHP 8 route attributes on controller methods under `Http/`, loaded automatically.
Routes can also be declared in YAML.

### Config & helpers
`config('app.foo', $default)` reads/writes the merged config. `src/helpers.php` defines global functions used throughout host apps: `config()`, `app()`, `trans()`/`appSetLocale()`/`appGetLocale()`, `render()` (Twig), `validate()` (Illuminate Validation), `csrfToken()`/`ionToken()`/`csrfCheck()`, `abort()`, `toJson()`/`toObject()`/`toString()`, `display()`, `newMailerDsn()`, `debugQuery()`. When adding helpers, wrap each in `if (!function_exists(...))`.

### Database (`src/Foundation/RegisterDB.php`)
Driven by `config('app.database_engine')` which may contain `'db'` (Illuminate Eloquent via Capsule `Manager`, bound as container `db`/`db.connection`/`db.schema`) and/or `'redbean'` (RedBeanPHP). Connections come from `config/database.php`. `RegisterDB::boot()` is idempotent and called per-request from controllers. `Ions\Support\DB` extends the Illuminate `DB` facade.

### Query building
`Ions\Builders\QueryBuilder` (+ `BuilderFields`/`BuilderSort`/`BuilderFilters` traits, `QueryBuilderRequest`) implements API-style request-driven filtering/sorting/field-selection over an Illuminate query, with `Exceptions/Invalid*Query` for validation. `Ions\Builders\DatatableBuilder`/`DatatableQuery` back jQuery DataTables server-side responses. Note `Ions\Bundles\QueryBuilder` is a separate, simpler request-to-query mapper with `eq/ne/gt/lt/like/in` operators.

### Auth (`src/Auth/`)
`Auth/Guard/Guard` wraps Cartalyst Sentinel (config at `Auth/Sentinel/config.php`); `GuardUser`/`GuardRole`/`GuardControl` add user/role/permission helpers. `Auth/Sentinel/User` is the user model.

### Support & Bundles
- `src/Support/` — thin wrappers/extensions over Symfony/Illuminate: `Request`, `Response`, `JsonResponse`, `Session`, `Cookie`, `Str`, `Arr`, `File`, `Storage`, `DB`, `Route`.
- `src/Bundles/` — feature modules: `Path` (all path resolution), `Cache`, `Logs` (Monolog), `Localization` (Symfony Translation), `IonUpload`/`IonDisk` (Flysystem local + AWS S3, switched by `FILESYSTEM_DISK` env), `AppKeys`, `Redirect`, `MRoute`/`Route`.
- `src/Foundation/Singleton` — base for the many static service classes; `BluePrint` is the controller interface.

### CLI commands (`src/commands/`)
Illuminate `Console\Command` classes, autoloaded via the `classmap` entry in `composer.json` (so they are not PSR-4 namespaced). The host app registers them in its own console binary. Code-generation commands read `.stub` templates colocated in `commands/` (e.g. `controller/`, `migrate/`, `stubs/`). Notable: `install:super` (`SuperCommand`) extracts bundled super-admin zips into the host `Http/`+`views/` and seeds its schema; plus controller/model/seeder/provider/route-list/migrate/rollback/schema/dump/key generators.

## Conventions

- Static service classes extending `Singleton` accessed via `ClassName::method()` are the dominant pattern — follow it rather than introducing instance-based services.
- New host-facing globals go in `src/helpers.php` behind `function_exists` guards.
- Errors that should halt a request use `abort($code, $message)` (throws Symfony `HttpException`) — Kernel renders JSON for API requests and HTML otherwise.
- Pinned Illuminate at `v9.52.4` and Symfony at `^6.4`; keep new deps compatible with PHP 8.2 (composer `platform` is locked to 8.2).
