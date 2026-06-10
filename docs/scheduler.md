# Scheduler

`Ions\Schedule\Scheduler` is the framework's cron scheduler: define tasks
fluently in one place, run them all from a **single** system cron entry
(`schedule:run`) — or, on hosts without cron access, via the built-in
`/cron/schedule` web route. Cron expressions are parsed by
`dragonmantank/cron-expression`.

## Defining tasks — `App\Schedule::boot(Scheduler $schedule)`

The host convention is an `App\Schedule` class whose `boot()` receives the
scheduler (static or instance method — both work; the class is configurable
via `config('app.schedule_class')`):

```php
<?php

namespace App;

use Ions\Schedule\Scheduler;

class Schedule
{
    public static function boot(Scheduler $schedule): void
    {
        // Console commands by signature (framework or host commands):
        $schedule->command('emails:send')->everyFiveMinutes();
        $schedule->command('reports:build', ['--force' => true])->dailyAt('03:00');

        // Plain PHP callables (name them — it keys the logs and overlap locks):
        $schedule->call(static fn () => Audit::prune(), 'audit-prune')
            ->daily()
            ->withoutOverlapping();
    }
}
```

The definition is **lazy**: nothing is built or invoked until something
actually resolves the scheduler (`schedule:run`, `schedule:list`, the web-cron
route or the `Ions\Support\Schedule` facade) — a normal request never pays for
it.

The `Ions\Support\Schedule` facade proxies to the same shared instance, e.g.
`Schedule::command('emails:send')->daily()` from anywhere after boot.

## Frequencies

Every task defaults to `* * * * *` (every minute). Fluent helpers:

| Method | Cron expression |
|---|---|
| `->everyMinute()` | `* * * * *` |
| `->everyFiveMinutes()` | `*/5 * * * *` |
| `->everyTenMinutes()` | `*/10 * * * *` |
| `->everyThirtyMinutes()` | `*/30 * * * *` |
| `->hourly()` | `0 * * * *` |
| `->daily()` | `0 0 * * *` |
| `->dailyAt('03:00')` | `0 3 * * *` |
| `->weekly()` | `0 0 * * 0` (Sunday 00:00) |
| `->monthly()` | `0 0 1 * *` |
| `->cron('*/10 2 * * 1')` | any raw expression (validated immediately) |

Times are evaluated in the host timezone (`TIME_ZONE` env, applied by
`Kernel::boot()`).

## Overlap protection — `withoutOverlapping()`

```php
$schedule->command('imports:run')->everyMinute()->withoutOverlapping(600);
```

While a `withoutOverlapping()` task runs, a cache lock
(`schedule.lock.<sha1(name)>` on the shared cache) blocks concurrent runs —
an overlapping invocation is **skipped**, not queued. The lock is always
released when the run finishes (success or failure); the TTL (default 3600s)
is only a safety net for runs that die hard. Without a usable cache the guard
degrades to plain execution.

## Running — `schedule:run` + crontab

Wire one system cron entry; the scheduler decides what is due each minute:

```cron
* * * * * cd /path/to/app && php vendor/ionzile/core/bin/ions schedule:run >> /dev/null 2>&1
```

`schedule:run` prints a per-task line (`Ran` / `Failed` / `Skipped`) and a
summary (`Ran 2 task(s), 0 failed, 1 skipped.`), and exits **non-zero when any
task failed**. A failing task never stops the others. Command tasks run
in-process through the console application, so every framework and host
command is available.

## Inspecting — `schedule:list`

```
$ php vendor/ionzile/core/bin/ions schedule:list
+--------------+--------------+---------------------------+
| Name         | Expression   | Next Run (Africa/Cairo)   |
+--------------+--------------+---------------------------+
| emails:send  | */5 * * * *  | 2026-06-10 12:35:00       |
| audit-prune  | 0 0 * * *    | 2026-06-11 00:00:00       |
+--------------+--------------+---------------------------+
```

## Web-cron — `GET /cron/schedule`

For hosts without system cron access, the framework registers a built-in
`/cron/schedule` route. When `App\Schedule::boot()` accepts a `Scheduler`,
hitting the route runs the **same due tasks** as `schedule:run` and answers
with a JSON summary:

```json
{"ran": 2, "failed": 0, "skipped": 1}
```

Point any external ping service at it every minute for cron parity.

### Legacy compatibility

Before 4.2, `/cron/schedule` dispatched `App\Schedule::boot` directly — the
zero-parameter `boot()` **was** the cron job. That behavior is fully
preserved: a host whose `boot()` takes no `Scheduler` parameter keeps the
exact controller-string dispatch (the new scheduler never calls a
zero-parameter `boot()`). Adopting the new signature is the opt-in. With no
schedule class at all the route answers 404.

Hosts still declaring `GO\Scheduler` jobs in a root (or `routes/`)
`schedule.php` file also keep working: `schedule:run` runs those legacy jobs
after the scheduler tasks. Migrating them to `App\Schedule::boot(Scheduler)`
is recommended.

## Logging

Task results go to `var/logs/schedule.log`:

- `Ran 'emails:send' in 412.3 ms.`
- `Task 'audit-prune' failed: <exception message>` (the failure is also
  reported by `schedule:run`'s exit code)
- `Skipped 'imports:run': overlap lock held by a previous run.`

Logging is best-effort — a broken log channel never breaks a run.

## Testing host schedules

The scheduler is a pure in-memory registry — no fake needed. Resolve it (or
build one) and assert:

```php
$scheduler = new \Ions\Schedule\Scheduler();
\App\Schedule::boot($scheduler);

expect($scheduler->dueTasks(new DateTimeImmutable('2026-06-10 03:00:00')))
    ->toHaveCount(1);

$summary = $scheduler->runDue(new DateTimeImmutable('2026-06-10 03:00:00'), $runner);
```
