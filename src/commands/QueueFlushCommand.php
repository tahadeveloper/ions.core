<?php

use Illuminate\Console\Command;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Ions\Foundation\Kernel;

/**
 * Delete all failed jobs from the failed-jobs store.
 *
 *   ions queue:flush               # delete everything
 *   ions queue:flush --hours=48    # only jobs that failed 48+ hours ago
 */
class QueueFlushCommand extends Command
{
    protected $signature = 'queue:flush {--hours= : Only flush failed jobs older than this many hours}';

    protected $description = 'Flush all of the failed queue jobs.';

    public function handle(): int
    {
        /** @var FailedJobProviderInterface $failer */
        $failer = Kernel::app()->get('queue.failer');

        $hours = $this->option('hours');
        $hours = is_numeric($hours) ? (int) $hours : null;

        $failer->flush($hours);

        $this->info($hours !== null
            ? "Failed jobs older than {$hours} hours deleted."
            : 'All failed jobs deleted.');

        return self::SUCCESS;
    }
}
