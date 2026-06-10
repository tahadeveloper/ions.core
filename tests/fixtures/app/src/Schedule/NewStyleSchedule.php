<?php

declare(strict_types=1);

namespace IonsFixture\Schedule;

use Ions\Schedule\Scheduler;

/**
 * New-style host schedule definition: boot() receives the Scheduler and
 * registers tasks on it. Wired into tests via the
 * config('app.schedule_class') escape hatch.
 */
class NewStyleSchedule
{
    public static bool $booted = false;

    public static int $runs = 0;

    public static function reset(): void
    {
        self::$booted = false;
        self::$runs = 0;
    }

    public static function boot(Scheduler $schedule): void
    {
        self::$booted = true;

        $schedule->call(static function (): void {
            self::$runs++;
        }, 'fixture-task')->everyMinute();
    }
}
