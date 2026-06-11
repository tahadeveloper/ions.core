<?php

use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Ions\Foundation\Kernel;

/**
 * Process jobs from a queue connection.
 *
 *   ions queue:work                       # work the default connection forever
 *   ions queue:work database --once       # process a single job, then exit
 *   ions queue:work database --stop-when-empty
 *
 * Built on the Illuminate queue Worker, wired to the framework's shared queue
 * manager, event dispatcher and (console) exception handler.
 */
class QueueWorkCommand extends Command
{
    protected $signature = 'queue:work
        {connection? : The name of the queue connection to work (defaults to queue.default)}
        {--queue= : The names of the queues to work (defaults to the connection default)}
        {--once : Only process the next job on the queue, then exit}
        {--stop-when-empty : Stop when the queue is empty}
        {--max-jobs=0 : The number of jobs to process before stopping (0 = unlimited)}
        {--sleep=3 : Seconds to sleep when no job is available}
        {--tries=1 : Number of attempts before a job is marked as failed (a $tries property on the job class wins)}
        {--backoff=0 : Seconds to delay a released job before retrying it (a $backoff property on the job class wins)}';

    protected $description = 'Process jobs from the queue.';

    public function handle(): int
    {
        $app = Kernel::app();

        /** @var QueueManager $manager */
        $manager = $app->get('queue');
        /** @var Dispatcher $events */
        $events = $app->get('events');
        /** @var ExceptionHandler $exceptions */
        $exceptions = $app->get(ExceptionHandler::class);

        // Record final failures in the failed-jobs store (queue.failer). The
        // Illuminate Worker only dispatches a JobFailed event — persisting it
        // is the work command's job (Laravel's WorkCommand does the same via
        // listenForEvents). Guarded by a container marker so repeated handle()
        // calls against one booted kernel don't register duplicate listeners.
        if (!$app->bound('queue.failer.listening')) {
            $app->instance('queue.failer.listening', true);

            $events->listen(JobFailed::class, static function (JobFailed $event) use ($app): void {
                /** @var FailedJobProviderInterface $failer */
                $failer = $app->get('queue.failer');

                $failer->log(
                    $event->connectionName,
                    $event->job->getQueue(),
                    $event->job->getRawBody(),
                    $event->exception,
                );
            });
        }

        $worker = new Worker(
            $manager,
            $events,
            $exceptions,
            static fn (): bool => false, // never "down for maintenance" in this context
        );

        $connection = (string) ($this->argument('connection') ?? config('queue.default', 'sync'));

        $queueOption = $this->option('queue');
        $queue = is_string($queueOption) && $queueOption !== ''
            ? $queueOption
            : $this->defaultQueueFor($connection);

        // Note: a $tries/$backoff property on the job class always wins over
        // these CLI defaults — the Worker prefers the job payload's
        // maxTries/backoff (captured at dispatch time) when present.
        $options = new WorkerOptions(
            backoff: (string) $this->option('backoff'),
            sleep: (int) $this->option('sleep'),
            maxTries: (int) $this->option('tries'),
            stopWhenEmpty: (bool) $this->option('stop-when-empty'),
            maxJobs: (int) $this->option('max-jobs'),
        );

        if ($this->option('once')) {
            $worker->runNextJob($connection, $queue, $options);

            return self::SUCCESS;
        }

        // Loop until the queue is drained (or max-jobs reached). We force
        // stopWhenEmpty so the command terminates rather than daemonising — host
        // apps run it under a process supervisor that restarts it.
        $options->stopWhenEmpty = true;

        return $worker->daemon($connection, $queue, $options);
    }

    /**
     * The default queue name configured for a connection (falls back to 'default').
     */
    private function defaultQueueFor(string $connection): string
    {
        $queue = config("queue.connections.{$connection}.queue", 'default');

        return is_string($queue) && $queue !== '' ? $queue : 'default';
    }
}
