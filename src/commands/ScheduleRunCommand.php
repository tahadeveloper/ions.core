<?php

use Illuminate\Console\Command;
use Ions\Bundles\Path;
use Ions\Console\Schedule as LegacyGoSchedule;
use Ions\Foundation\Kernel;
use Ions\Schedule\Scheduler;
use Ions\Schedule\Task;

/**
 * schedule:run — execute every due task of the Ions scheduler (the host's
 * App\Schedule::boot(Scheduler) definitions), then — for backward
 * compatibility — any legacy GO\Scheduler jobs declared in the host's
 * schedule.php file. Wire a single system cron entry to it:
 *
 *     * * * * * cd /path/to/app && php vendor/ionzile/core/bin/ions schedule:run >> /dev/null 2>&1
 *
 * Command tasks run through $this->call(), i.e. inside the same console
 * application — so every framework + host command is available. Exits
 * non-zero when any task failed (a failing task never stops the others).
 */
class ScheduleRunCommand extends Command
{
    protected $signature = 'schedule:run';
    protected $description = 'Run the scheduled tasks that are due.';

    public function handle(): int
    {
        /** @var Scheduler $scheduler */
        $scheduler = Kernel::app()->get('schedule');

        $summary = $scheduler->runDue(
            new DateTimeImmutable(),
            fn (string $signature, array $arguments): int => (int) $this->call($signature, $arguments),
            function (Task $task, string $status, ?Throwable $e, float $durationMs): void {
                match ($status) {
                    'ran' => $this->line(sprintf('  <info>Ran</info>     %s (%.1f ms)', $task->getName(), $durationMs)),
                    'failed' => $this->line(sprintf('  <error>Failed</error>  %s: %s', $task->getName(), $e?->getMessage() ?? 'unknown error')),
                    default => $this->line(sprintf('  <comment>Skipped</comment> %s (overlap lock held)', $task->getName())),
                };
            }
        );

        $this->runLegacyGoSchedule();

        $this->info(sprintf(
            'Ran %d task(s), %d failed, %d skipped.',
            $summary['ran'],
            $summary['failed'],
            $summary['skipped']
        ));

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * BC bridge: hosts that still declare GO\Scheduler jobs in a schedule.php
     * file (host root or routes/) keep running on every schedule:run.
     */
    private function runLegacyGoSchedule(): void
    {
        if (!is_file(Path::root('schedule.php')) && !is_file(Path::route('schedule.php'))) {
            return;
        }

        $scheduler = LegacyGoSchedule::build();
        $scheduler->run();

        $output = $scheduler->getVerboseOutput();
        if ($output !== '') {
            $this->line($output);
        }
    }
}
