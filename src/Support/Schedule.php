<?php

declare(strict_types=1);

namespace Ions\Support;

use Ions\Foundation\Kernel;
use Ions\Schedule\Scheduler;
use Ions\Schedule\Task;

/**
 * Thin static facade over the container-bound {@see Scheduler} ('schedule',
 * registered lazily by {@see \Ions\Providers\ScheduleProvider}).
 *
 *     Schedule::command('emails:send')->daily();
 *     Schedule::call(fn () => Reports::purge(), 'reports-purge')->everyFiveMinutes();
 *
 * No fake: scheduling is a pure in-memory registry — tests assert on
 * Schedule::tasks() (or runDue() with a frozen now) directly.
 */
final class Schedule
{
    /**
     * The shared container-bound scheduler.
     */
    public static function scheduler(): Scheduler
    {
        /** @var Scheduler $scheduler */
        $scheduler = Kernel::app()->get('schedule');

        return $scheduler;
    }

    /**
     * Schedule a console command by signature.
     *
     * @param array<int|string, mixed> $arguments
     */
    public static function command(string $signature, array $arguments = []): Task
    {
        return self::scheduler()->command($signature, $arguments);
    }

    /**
     * Schedule a PHP callable.
     */
    public static function call(callable $callback, ?string $name = null): Task
    {
        return self::scheduler()->call($callback, $name);
    }

    /**
     * @return list<Task>
     */
    public static function tasks(): array
    {
        return self::scheduler()->tasks();
    }
}
