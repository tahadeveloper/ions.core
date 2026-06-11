<?php

declare(strict_types=1);

namespace Ions\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Base class for framework queue jobs.
 *
 * Extending this gives a job the standard Illuminate queue traits (serialization,
 * connection/queue targeting, retry helpers) and marks it ShouldQueue so the
 * queue worker invokes handle() through CallQueuedHandler. Dispatch with the
 * dispatch() helper:
 *
 *   final class SendWelcome extends Job {
 *       public function __construct(private int $userId) {}
 *       public function handle(): void { ... }
 *   }
 *
 *   dispatch(new SendWelcome($id));            // default connection
 *   dispatch((new SendWelcome($id))->onConnection('database'));
 *
 * On the 'sync' connection handle() runs immediately; on 'database' the job is
 * persisted to the jobs table and processed by `queue:work`.
 *
 * Retries: declare `public int $tries` (attempts before the job lands in
 * failed_jobs) and/or `public int|array $backoff` (seconds to delay each
 * release) on the job class — both are captured into the payload at dispatch
 * time and win over the queue:work --tries/--backoff CLI defaults. Final
 * failures are recorded by the worker via the 'queue.failer' store
 * (config('queue.failed')); inspect/recover with queue:failed / queue:retry /
 * queue:forget / queue:flush.
 */
abstract class Job implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Execute the job.
     */
    abstract public function handle(): void;
}
