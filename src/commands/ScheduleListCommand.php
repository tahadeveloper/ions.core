<?php

use Illuminate\Console\Command;
use Ions\Foundation\Kernel;
use Ions\Schedule\Scheduler;
use Ions\Schedule\Task;

/**
 * schedule:list — tabulate every scheduled task: name, cron expression and
 * the next run time (in the host timezone, set by Kernel::boot() from the
 * TIME_ZONE env var).
 */
class ScheduleListCommand extends Command
{
    protected $signature = 'schedule:list';
    protected $description = 'List the scheduled tasks and their next run time.';

    public function handle(): int
    {
        /** @var Scheduler $scheduler */
        $scheduler = Kernel::app()->get('schedule');

        $tasks = $scheduler->tasks();
        if ($tasks === []) {
            $this->info('No scheduled tasks defined.');

            return self::SUCCESS;
        }

        $now = new DateTimeImmutable();

        $this->table(
            ['Name', 'Expression', 'Next Run (' . date_default_timezone_get() . ')'],
            array_map(static fn (Task $task): array => [
                $task->getName(),
                $task->getExpression(),
                $task->nextRunDate($now)->format('Y-m-d H:i:s'),
            ], $tasks)
        );

        return self::SUCCESS;
    }
}
