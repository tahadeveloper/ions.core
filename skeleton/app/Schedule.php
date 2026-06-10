<?php

declare(strict_types=1);

namespace App;

use Ions\Schedule\Scheduler;

/**
 * Scheduled tasks. boot() receives the framework scheduler; register your
 * tasks fluently and run them with a single system cron entry:
 *
 *     * * * * * cd /path/to/app && php vendor/ionzile/core/bin/ions schedule:run >> /dev/null 2>&1
 *
 * Without system cron access, hitting GET /cron/schedule runs the same due
 * tasks (web-cron fallback). Inspect the schedule with `ions schedule:list`.
 */
class Schedule
{
    public static function boot(Scheduler $schedule): void
    {
        // $schedule->command('emails:send')->everyFiveMinutes();
        // $schedule->command('reports:build', ['--force' => true])->dailyAt('03:00');
        // $schedule->call(static fn () => /* prune something */ null, 'prune')->daily()->withoutOverlapping();
    }
}
