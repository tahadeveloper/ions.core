<?php

use Illuminate\Console\Command;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Ions\Foundation\Kernel;

/**
 * Delete a single failed job from the failed-jobs store.
 *
 *   ions queue:forget 9d2e...-uuid          # IDs from queue:failed
 */
class QueueForgetCommand extends Command
{
    protected $signature = 'queue:forget {id : The ID of the failed job (see queue:failed)}';

    protected $description = 'Delete a failed queue job.';

    public function handle(): int
    {
        /** @var FailedJobProviderInterface $failer */
        $failer = Kernel::app()->get('queue.failer');

        $id = (string) $this->argument('id');

        if (!$failer->forget($id)) {
            $this->error("No failed job matches ID [{$id}].");

            return self::FAILURE;
        }

        $this->info("Failed job [{$id}] deleted.");

        return self::SUCCESS;
    }
}
