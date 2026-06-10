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
| `schedule:run` | `ScheduleRunCommand` | Run the due scheduled tasks. |
| `queue:work` | `QueueWorkCommand` | Process jobs from the queue. |
| `openapi:generate` | `OpenApiCommand` | Export the routes as an OpenAPI 3.0 spec (see [resources.md](resources.md)). |

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
into the host `{src|app}/` tree (honouring the `src/` → `app/` layout fallback),
refuses to overwrite an existing file (exit code 1) unless `--force` is passed,
and fills a stub from `src/commands/stubs/`:

```bash
php bin/ions make:resource UserResource                # {src|app}/Http/Resources — extends Ions\Http\Resource
php bin/ions make:resource UserCollection --collection #   … extends Ions\Http\ResourceCollection (wired to UserResource)
php bin/ions make:request StoreUserRequest             # {src|app}/Http/Requests — extends Ions\Http\FormRequest
php bin/ions make:job SendWelcomeJob                   # {src|app}/Jobs — extends Ions\Queue\Job
php bin/ions make:event UserRegistered                 # {src|app}/Events — plain payload class
php bin/ions make:listener SendWelcomeEmail            # {src|app}/Listeners — handle(object $event)
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
   `{src|app}/Commands` directory is registered automatically (resolved by its
   declared namespace, so it works under either the `src/` or `app/` layout).
   New classes scaffolded by `make:command` are therefore picked up without any
   further wiring.

Duplicate registrations (a class in both the config list and the directory) are
de-duplicated.

## Task scheduling

`Ions\Console\Schedule` builds a `GO\Scheduler`
(`peppeocchi/php-cron-scheduler`) from a host `schedule.php` definition. The host
declares its jobs in a `schedule.php` at the project root (or `routes/schedule.php`)
that returns a closure receiving the scheduler:

```php
<?php

return function (\GO\Scheduler $schedule): void {
    $schedule->raw('php vendor/ionzile/core/bin/ions cache:clear')->daily();
};
```

Run the due jobs with the `schedule:run` command, wired to a single system cron
entry:

```cron
* * * * * cd /path/to/app && php vendor/ionzile/core/bin/ions schedule:run >> /dev/null 2>&1
```
