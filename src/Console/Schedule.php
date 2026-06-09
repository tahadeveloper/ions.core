<?php

declare(strict_types=1);

namespace Ions\Console;

use Closure;
use GO\Scheduler;
use Ions\Bundles\Path;

/**
 * Task scheduling entry point.
 *
 * Builds a {@see Scheduler} (peppeocchi/php-cron-scheduler) from the host
 * application's schedule definition. The host declares its jobs in a
 * `schedule.php` file that returns a closure receiving the Scheduler:
 *
 *     // schedule.php (host root, or routes/schedule.php)
 *     return function (\GO\Scheduler $schedule) {
 *         $schedule->raw('php bin/ions cache:clear')->daily();
 *     };
 *
 * `schedule:run` (see {@see \ScheduleRunCommand}) loads this and runs the
 * due jobs; wire a single system cron entry to it:
 *
 *     * * * * * cd /path/to/app && php vendor/ionzile/core/bin/ions schedule:run >> /dev/null 2>&1
 */
class Schedule
{
    /**
     * Build the scheduler and register the host application's jobs.
     *
     * @param array<string, mixed> $config Optional Scheduler config overrides.
     */
    public static function build(array $config = []): Scheduler
    {
        $scheduler = new Scheduler($config);

        $definition = self::loadDefinition();
        if ($definition !== null) {
            $definition($scheduler);
        }

        return $scheduler;
    }

    /**
     * Locate and load the host schedule definition closure, if present.
     * Checks the host root `schedule.php` first, then `routes/schedule.php`.
     */
    private static function loadDefinition(): ?Closure
    {
        foreach ([Path::root('schedule.php'), Path::route('schedule.php')] as $file) {
            if (is_file($file)) {
                $definition = require $file;
                if ($definition instanceof Closure) {
                    return $definition;
                }
            }
        }

        return null;
    }
}
