<?php

declare(strict_types=1);

namespace IonsFixture\Schedule;

/**
 * Legacy host schedule class: zero-parameter boot() — the method IS the cron
 * job itself (the pre-4.2 /cron/schedule convention). The framework must keep
 * dispatching this exactly like any 'Class::boot' controller string and must
 * NOT pass it a Scheduler.
 */
class LegacySchedule
{
    public static int $runs = 0;

    public static function reset(): void
    {
        self::$runs = 0;
    }

    public static function boot(): void
    {
        self::$runs++;
    }
}
