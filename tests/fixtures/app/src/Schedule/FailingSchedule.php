<?php

declare(strict_types=1);

namespace IonsFixture\Schedule;

use Ions\Schedule\Scheduler;
use RuntimeException;

/**
 * Registers a failing task FIRST and a succeeding task second, so tests can
 * assert failure isolation (the second task still runs) and the failure exit
 * code of schedule:run.
 */
class FailingSchedule
{
    public static int $succeededRuns = 0;

    public static function reset(): void
    {
        self::$succeededRuns = 0;
    }

    public static function boot(Scheduler $schedule): void
    {
        $schedule->call(static function (): void {
            throw new RuntimeException('fixture task failure');
        }, 'failing-task')->everyMinute();

        $schedule->call(static function (): void {
            self::$succeededRuns++;
        }, 'succeeding-task')->everyMinute();
    }
}
