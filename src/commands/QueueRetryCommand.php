<?php

use Illuminate\Console\Command;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\QueueManager;
use Ions\Foundation\Kernel;

/**
 * Push failed jobs back onto their original connection/queue.
 *
 *   ions queue:retry 9d2e...-uuid          # retry one (IDs from queue:failed)
 *   ions queue:retry uuid-1 uuid-2         # retry several
 *   ions queue:retry --all                 # retry everything
 *
 * The stored payload is re-pushed with its attempts counter reset, then the
 * failed-jobs row is forgotten. Run queue:work to process the retried jobs.
 */
class QueueRetryCommand extends Command
{
    protected $signature = 'queue:retry
        {id?* : The ID(s) of the failed job (see queue:failed)}
        {--all : Retry all failed jobs}';

    protected $description = 'Retry failed queue jobs by pushing them back onto the queue.';

    public function handle(): int
    {
        $app = Kernel::app();

        /** @var FailedJobProviderInterface $failer */
        $failer = $app->get('queue.failer');
        /** @var QueueManager $queue */
        $queue = $app->get('queue');

        $ids = $this->option('all') ? $failer->ids() : (array) $this->argument('id');

        if ($ids === []) {
            $this->info('No failed jobs to retry. Pass IDs (see queue:failed) or use --all.');

            return self::SUCCESS;
        }

        $missing = false;

        foreach ($ids as $id) {
            $job = $failer->find($id);

            if ($job === null) {
                $this->error("Unable to find failed job with ID [{$id}].");
                $missing = true;

                continue;
            }

            $queue->connection((string) $job->connection)->pushRaw(
                $this->resetAttempts((string) $job->payload),
                (string) $job->queue,
            );

            $failer->forget($id);

            $this->info("Failed job [{$id}] pushed back onto the [{$job->queue}] queue.");
        }

        return $missing ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Reset the payload's attempts counter so drivers that store attempts in
     * the payload (e.g. redis) start the retried job from a clean slate.
     */
    private function resetAttempts(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            return $payload;
        }

        if (isset($decoded['attempts'])) {
            $decoded['attempts'] = 0;
        }

        return (string) json_encode($decoded);
    }
}
