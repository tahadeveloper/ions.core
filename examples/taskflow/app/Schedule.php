<?php

declare(strict_types=1);

namespace App;

use App\Mail\DigestMail;
use App\Models\Task;
use App\Models\User;
use Ions\Foundation\Kernel;
use Ions\Schedule\Scheduler;

/**
 * Host schedule definition (9.4). The framework discovers App\Schedule by
 * convention and calls boot() with the shared Scheduler; both runners —
 * `php bin/ions schedule:run` and the /cron/schedule web route — drive it.
 *
 * Wire your crontab to fire schedule:run every minute:
 *
 *     * * * * * cd /path/to/app && php bin/ions schedule:run >> /dev/null 2>&1
 */
final class Schedule
{
    public static function boot(Scheduler $schedule): void
    {
        // Daily maintenance: prune in-app notifications that were read more than
        // 30 days ago. withoutOverlapping() guards against a slow run still
        // executing when the next tick fires; ->name() gives the lock a stable
        // identity across deploys.
        $schedule->call(static function (): void {
            self::pruneReadNotifications();
        }, 'prune-read-notifications')
            ->daily()
            ->withoutOverlapping();

        // Weekly digest: mail every user a count of their open (todo/doing)
        // tasks. Queued onto the database connection so a real run hands the
        // sends to the worker rather than blocking the scheduler tick.
        $schedule->call(static function (): void {
            self::dispatchWeeklyDigest();
        }, 'weekly-digest')
            ->weekly();
    }

    /**
     * Delete notification rows read over 30 days ago. Returns the deleted count
     * so the closure is testable in isolation.
     */
    public static function pruneReadNotifications(): int
    {
        $cutoff = (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');

        return (int) Kernel::app()->get('db')->connection()
            ->table((string) config('notifications.table', 'notifications'))
            ->whereNotNull('read_at')
            ->where('read_at', '<', $cutoff)
            ->delete();
    }

    /**
     * Queue a DigestMail for every user, carrying their current open-task count.
     */
    public static function dispatchWeeklyDigest(): void
    {
        foreach (User::query()->get() as $user) {
            $openTasks = Task::query()
                ->where('assignee_id', $user->getKey())
                ->whereIn('status', [Task::STATUS_TODO, Task::STATUS_DOING])
                ->count();

            (new DigestMail((string) $user->email, (string) $user->name, $openTasks))
                ->queue('database');
        }
    }
}
