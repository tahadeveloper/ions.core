<?php

use Illuminate\Console\Command;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Ions\Foundation\Kernel;

/**
 * List the jobs recorded in the failed-jobs store (config('queue.failed')).
 *
 *   ions queue:failed
 *
 * Shows each failed job's ID (uuid), connection, queue, job class (from the
 * payload's displayName) and failure time. Use queue:retry / queue:forget /
 * queue:flush to act on the listed IDs.
 */
class QueueFailedCommand extends Command
{
    protected $signature = 'queue:failed';

    protected $description = 'List all of the failed queue jobs.';

    public function handle(): int
    {
        /** @var FailedJobProviderInterface $failer */
        $failer = Kernel::app()->get('queue.failer');

        $failed = $failer->all();

        if ($failed === []) {
            $this->info('No failed jobs.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($failed as $job) {
            $payload = json_decode((string) ($job->payload ?? ''), true);

            $rows[] = [
                (string) ($job->id ?? ''),
                (string) ($job->connection ?? ''),
                (string) ($job->queue ?? ''),
                is_array($payload) ? (string) ($payload['displayName'] ?? '[unknown]') : '[unknown]',
                (string) ($job->failed_at ?? ''),
            ];
        }

        $this->table(['ID', 'Connection', 'Queue', 'Class', 'Failed At'], $rows);

        return self::SUCCESS;
    }
}
