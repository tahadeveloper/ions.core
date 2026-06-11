# Console

The Ions framework ships first-class console support: a console **Kernel** that
boots the framework container and discovers + registers commands, a single
`bin/ions` entry point, and a `make:command` generator.

## The console Kernel

`Ions\Console\Kernel` wraps an `Illuminate\Console\Application` (Symfony Console
underneath) and wires it to the booted framework container so every command can
resolve framework services (`config()`, `db`, `session`, the filesystem, …).

```php
use Ions\Console\Kernel;

$kernel = Kernel::boot();          // boots Ions\Foundation\Kernel + the container
$exit   = $kernel->run();          // run from $argv; returns the exit code
```

API:

| Method | Description |
| ------ | ----------- |
| `Kernel::boot(?string $basePath = null): self` | Boots the framework container (optionally against a given host-app root) and builds a fully-wired console application with every command registered. |
| `$kernel->getApplication(): Illuminate\Console\Application` | The underlying console application — use `->has('name')` / `->find('name')` to inspect or fetch a command. |
| `$kernel->run(?array $argv = null): int` | Runs the console application and returns the process exit code. Pass an explicit `$argv` (e.g. `['ions', 'list']`) or `null` to use the global `$argv`. |

Internally the Kernel passes the Ions container to the console application
through a thin `Ions\Console\ConsoleContainerProxy`. The proxy forwards every
resolution (`make`/`bound`/`call`/…) to the booted Ions container while adding
the `runningUnitTests()` method the Illuminate console layer expects.

## Running commands — `bin/ions`

`bin/ions` is the executable entry point. It locates Composer's autoloader
(whether this library runs standalone or installed inside a host application's
`vendor/`), boots the Kernel and runs it. It is also declared under `bin` in
`composer.json`, so Composer symlinks it into the host app's `vendor/bin`.

```bash
# from the host-app root
php vendor/ionzile/core/bin/ions list                 # list every command
php vendor/ionzile/core/bin/ions route:list           # run a command
php vendor/ionzile/core/bin/ions make:command SendReports
```

## Framework commands

These ship with the framework and are always registered:

| Signature | Class | Purpose |
| --------- | ----- | ------- |
| `make:key` | `KeyCommand` | Create the app key (and optional JWT key). |
| `make:model` | `ModelCommand` | Generate an Eloquent model. |
| `make:provider` | `ProviderCommand` | Generate a controller-style provider. |
| `make:service-provider` | `MakeServiceProviderCommand` | Generate a container service provider. |
| `make:middleware` | `MakeMiddlewareCommand` | Generate an HTTP middleware. |
| `make:command` | `MakeCommandCommand` | Generate a console command (see below). |
| `make:resource` | `MakeResourceCommand` | Generate an API resource (`--collection` for a `ResourceCollection`). |
| `make:request` | `MakeRequestCommand` | Generate a form request (`Ions\Http\FormRequest`). |
| `make:job` | `MakeJobCommand` | Generate a queue job (`Ions\Queue\Job`). |
| `make:event` | `MakeEventCommand` | Generate a plain event class. |
| `make:listener` | `MakeListenerCommand` | Generate an event listener (`--event=` to type-hint the event). |
| `make:test` | `MakeTestCommand` | Generate a host test in `tests/` (`--unit` for a plain PHPUnit test). |
| `make:control` | `ControllerCommand` | Generate a controller. |
| `make:seeder` | `SeederCommand` | Generate a seeder. |
| `make:schema` | `SchemaCommand` | Generate a schema/migration. |
| `route:list` | `Ions\commands\RouteListCommand` | List the registered routes. |
| `migrate` / `migrate:rollback` | `MigrateCommand` / `RollBackCommand` | Run / roll back migrations. |
| `schema:dump` | `DumpCommand` | Dump a database schema. |
| `install:super` | `SuperCommand` | Install the bundled super-admin. |
| `install:vue` | `InstallVueCommand` | Scaffold a Vue 3 + Vite frontend into the host (see [assets.md](assets.md)). |
| `install:assets` | `InstallAssetsCommand` | Scaffold plain CSS/JS starters into `public/assets/` — no build step (see [assets.md](assets.md)). |
| `schedule:run` | `ScheduleRunCommand` | Run the due scheduled tasks (see [scheduler.md](scheduler.md)). |
| `schedule:list` | `ScheduleListCommand` | List the scheduled tasks with expression + next run time. |
| `queue:work` | `QueueWorkCommand` | Process jobs from the queue (`--once`, `--tries`, `--backoff`, …). |
| `queue:failed` | `QueueFailedCommand` | List the failed queue jobs. |
| `queue:retry` | `QueueRetryCommand` | Push failed jobs back onto the queue (`{id…}` or `--all`). |
| `queue:forget` | `QueueForgetCommand` | Delete a failed queue job. |
| `queue:flush` | `QueueFlushCommand` | Flush all failed queue jobs (`--hours=N` to age-filter). |
| `openapi:generate` | `OpenApiCommand` | Export the routes as an OpenAPI 3.0 spec (see [resources.md](resources.md)). |
| `doctor` | `Ions\commands\DoctorCommand` | Diagnose the host app (env, APP_KEY, writable `var/`, caches, DB, extensions, security posture) — see [Diagnostics](#diagnostics--doctor). |
| `serve` | `Ions\commands\ServeCommand` | Run the app on PHP's built-in dev server (`--host=127.0.0.1`, `--port=8000`) — development only. |
| `down` | `Ions\commands\DownCommand` | Enter maintenance mode: every request gets a themeable 503 (`--retry=N` for Retry-After, `--secret=S` for a bypass URL) — see [deploy.md](deploy.md#maintenance-mode). |
| `up` | `Ions\commands\UpCommand` | Leave maintenance mode (removes `var/maintenance.php`). |

The framework commands live in `src/commands` and are autoloaded via the
Composer `classmap` entry (most are in the global namespace). The console Kernel
holds their class names in a list and registers each instance.

## Writing a command — `make:command`

```bash
php vendor/ionzile/core/bin/ions make:command SendReportsCommand
# or with an explicit terminal signature:
php vendor/ionzile/core/bin/ions make:command SendReportsCommand --command="reports:send {--daily}"
```

This writes `app/Commands/SendReportsCommand.php` (or `src/Commands/…` for
projects still using the `src/` layout) from `src/commands/stubs/command.stub`:

```php
<?php

namespace App\Commands;

use Illuminate\Console\Command;

class SendReportsCommand extends Command
{
    protected $signature = 'app:send-reports';
    protected $description = 'Command description';

    public function handle(): int
    {
        // ...
        return self::SUCCESS;
    }
}
```

When `--command` is omitted the signature defaults to `app:<kebab-name>` (with a
trailing `Command` stripped from the class name).

## Class generators — `make:*`

Six additional generators scaffold host application classes. Each one writes
into the host `{app|src}/` tree (`app/` first since 4.2, `src/` layout fallback),
refuses to overwrite an existing file (exit code 1) unless `--force` is passed,
and fills a stub from `src/commands/stubs/`:

```bash
php bin/ions make:resource UserResource                # {app|src}/Http/Resources — extends Ions\Http\Resource
php bin/ions make:resource UserCollection --collection #   … extends Ions\Http\ResourceCollection (wired to UserResource)
php bin/ions make:request StoreUserRequest             # {app|src}/Http/Requests — extends Ions\Http\FormRequest
php bin/ions make:job SendWelcomeJob                   # {app|src}/Jobs — extends Ions\Queue\Job
php bin/ions make:event UserRegistered                 # {app|src}/Events — plain payload class
php bin/ions make:listener SendWelcomeEmail            # {app|src}/Listeners — handle(object $event)
php bin/ions make:listener SendWelcomeEmail --event=UserRegistered   # type-hints App\Events\UserRegistered
php bin/ions make:test PingTest                        # host tests/ — extends Ions\Testing\TestCase ($basePath wired)
php bin/ions make:test MathTest --unit                 # host tests/ — plain PHPUnit\Framework\TestCase
```

`make:listener --event=` accepts either a short name (resolved to
`App\Events\…`) or a fully-qualified class name (e.g.
`Ions\Events\RequestHandled`). `make:test` writes to the host-root `tests/`
directory (`Path::tests()`); the generated feature test sets
`protected string $basePath = __DIR__ . '/..'` so it boots the surrounding app —
see [testing.md](testing.md).

## Registering host commands

The Kernel registers host commands two ways (both can be combined):

1. **Explicit list — `config('console.commands')`.** Add a `config/console.php`
   that returns an array of fully-qualified command class names:

   ```php
   <?php

   return [
       'commands' => [
           \App\Commands\SendReportsCommand::class,
       ],
   ];
   ```

2. **Auto-discovery of `app/Commands`.** Every `*.php` class found in the host
   `{app|src}/Commands` directory is registered automatically (resolved by its
   declared namespace, so it works under either the `src/` or `app/` layout).
   New classes scaffolded by `make:command` are therefore picked up without any
   further wiring.

Duplicate registrations (a class in both the config list and the directory) are
de-duplicated.

## Diagnostics — `doctor`

`ions doctor` diagnoses the host application and exits non-zero on any
critical misconfiguration — run it after setup, after deploys, and in CI. The
check logic lives in `Ions\Foundation\Doctor` (the command is a thin renderer).

```bash
php vendor/ionzile/core/bin/ions doctor          # human-readable table + summary
php vendor/ionzile/core/bin/ions doctor --json   # structured JSON for CI
```

### Checks

| Check id | What it verifies | On problem |
| -------- | ---------------- | ---------- |
| `env_loaded` | A `.env` file exists at the host root. | WARN (real env vars are fine) |
| `app_key` | `APP_KEY` is present and ≥ 32 bytes (the `Kernel::buildJwt()` minimum). | **FAIL** |
| `app_url` | `config('app.app_url')` is set — `signedRoute()`/`url()` links and the `appUrl` view global break without it. | WARN |
| `dual_app_dirs` | The host does not carry BOTH `app/` and `src/` — `app/` wins since 4.2, so a lingering `src/` is silently ignored; consolidate into `app/`. Row only appears on dual-layout hosts. | WARN |
| `writable_var` / `writable_cache` / `writable_logs` / `writable_templates` | `var/`, `var/cache`, `var/logs`, `var/templates` exist and are writable. | **FAIL** (unwritable), WARN (missing but creatable) |
| `route_cache` / `config_cache` / `providers_cache` | The production caches (`var/cache/routes/`, `config.php`, `providers.php`) are built. | INFO — run `ions optimize` |
| `db` | When the `db` engine is configured, a trivial `select 1` succeeds. | **FAIL**; SKIP when no engine is configured |
| `php_extensions` | Required extensions (`openssl`, `sodium`, `zip`, plus `pdo` when a DB is configured) are loaded. | **FAIL** |
| `csrf` | `config('app.csrf.enabled')` is not disabled. | WARN |
| `trusted_hosts` | `config('app.trusted_hosts')` is set. | WARN |
| `trusted_proxies` | Whether `config('app.trusted_proxies')` is set — required behind a TLS-terminating proxy/LB for `isSecure()`, client IPs, HSTS and `cookie_secure => 'auto'`. | INFO — serving directly needs none |
| `session_cookies` | No *explicit* insecure overrides (`cookie_secure => false`, `cookie_httponly => false`) — secure defaults apply since 4.1. | WARN |
| `cors` | `config('app.cors.origins')` is not the wildcard `['*']`. | WARN |
| `debug` | `APP_DEBUG` is off. | WARN — doctor cannot know whether this host is production |
| `discovery` | Which provider-discovery mode is active (explicit list / cached / live). | INFO |

### Severities and exit code

- **FAIL** — critical misconfig; `doctor` exits with a non-zero code.
- **WARN** — risky but running (debug on, wildcard CORS, …); never fails the run.
- **INFO** / **SKIP** — state worth knowing / not applicable.

The summary line reads `12 ok, 3 warnings, 0 failures (3 info, 0 skipped)`.

### CI usage

```yaml
# e.g. a GitHub Actions step — fails the job on any critical misconfig
- run: php vendor/ionzile/core/bin/ions doctor --json > doctor.json
```

The JSON payload is `{"checks": [{id, label, status, message}, …],
"summary": {ok, info, warn, fail, skip}, "ok": bool}`.

### The `/up` health endpoint

The same diagnostics are reachable over HTTP (4.3): the kernel registers a
built-in `GET /up` route (like `/cron/schedule`) handled by
`Ions\Http\HealthController`:

```bash
curl https://example.com/up                          # -> 200 'ok' (liveness)
curl "https://example.com/up?checks=1&token=$TOKEN"  # -> doctor JSON (readiness)
```

- **`GET /up`** answers a plain `200 ok` — point load balancers and uptime
  monitors here. The response is `Cache-Control: no-store` (exempt from the
  kernel's public web caching defaults) so a CDN can never mask an outage.
- **`GET /up?checks=1&token=…`** runs `Doctor::run()` and returns the exact
  `doctor --json` payload above. The status is **200 even when checks fail** —
  read `ok: false` in the body; non-200 means the app itself is down. Because
  doctor output names paths and config state, this variant requires
  `config('app.health.token')` to be set and to match `?token=` — otherwise
  403. No token configured = checks locked.
- Disable the route entirely with `config('app.health.enabled') => false`
  (then `/up` 404s).

The route runs through the normal web middleware stack (security headers,
CORS, session start when bound) — deliberate: a probe that exercises the real
pipeline is a more honest signal than one that bypasses it.

## Task scheduling

Tasks are defined fluently in `App\Schedule::boot(Scheduler $schedule)` and
run by `schedule:run` from a single system cron entry — see
**[scheduler.md](scheduler.md)** for the full reference (frequencies,
`withoutOverlapping()`, `schedule:list`, the `/cron/schedule` web-cron route
and logging):

```cron
* * * * * cd /path/to/app && php vendor/ionzile/core/bin/ions schedule:run >> /dev/null 2>&1
```

Legacy `GO\Scheduler` jobs declared in a host `schedule.php` (project root or
`routes/`) returning a closure that receives the `\GO\Scheduler` keep running
on every `schedule:run` — migrating them to `App\Schedule::boot(Scheduler)` is
recommended.
