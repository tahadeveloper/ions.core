<?php

declare(strict_types=1);

namespace Ions\Schedule;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Task registry + due-task orchestrator.
 *
 * Hosts define tasks in App\Schedule::boot(Scheduler $schedule) (resolved
 * lazily from the 'schedule' container binding by ScheduleProvider, or via
 * the Ions\Support\Schedule facade). Both runners — the schedule:run console
 * command and the /cron/schedule web route — drive the SAME instance through
 * {@see runDue()}.
 *
 * Execution policy:
 *  - a failing task NEVER stops the remaining tasks (failure isolation);
 *  - command tasks run through a caller-injected runner so the Scheduler has
 *    no console dependency (the command injects $this->call(), the web cron
 *    injects a console-application call) — a non-zero exit counts as failed;
 *  - ->withoutOverlapping() tasks take a cache lock (add() is atomic enough
 *    per store) holding a per-run owner token, released in finally only while
 *    the key still holds that token — a run that outlives its TTL can never
 *    delete a successor's lock. The TTL is therefore both the crash safety
 *    net and the maximum protected window;
 *  - results are logged through the injected PSR logger (schedule.log when
 *    built by ScheduleProvider) — logging failures are swallowed, they must
 *    never break a run.
 *
 * Both collaborators are optional so the class stays trivially unit-testable:
 * without a cache the overlap guard degrades to plain execution, without a
 * logger nothing is logged.
 */
final class Scheduler
{
    /** @var list<Task> */
    private array $tasks = [];

    public function __construct(
        private readonly ?CacheRepository $cache = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Schedule a console command by signature.
     *
     * @param array<int|string, mixed> $arguments
     */
    public function command(string $signature, array $arguments = []): Task
    {
        return $this->tasks[] = Task::command($signature, $arguments);
    }

    /**
     * Schedule a PHP callable. Unnamed closures get a positional default name
     * ('closure-1', 'closure-2', …) so overlap locks and logs stay distinct.
     */
    public function call(callable $callback, ?string $name = null): Task
    {
        $task = Task::callable($callback, $name ?? 'closure-' . (count($this->tasks) + 1));
        if ($name === null) {
            // Remembered so runDue() can warn if this task later takes an
            // overlap lock under a positional name (see the notice there).
            $task->markAutoNamed();
        }

        return $this->tasks[] = $task;
    }

    /**
     * @return list<Task>
     */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /**
     * @return list<Task>
     */
    public function dueTasks(DateTimeImmutable $now): array
    {
        return array_values(array_filter($this->tasks, static fn (Task $task): bool => $task->isDue($now)));
    }

    /**
     * Run every task due at $now.
     *
     * @param callable(string, array<int|string, mixed>): mixed $commandRunner Receives (signature, arguments)
     *        for command tasks; an int return is treated as the exit code.
     * @param callable(Task, 'ran'|'failed'|'skipped', ?Throwable, float): void|null $onResult Optional per-task
     *        reporter, receiving the task, its outcome, the failure (if any) and the duration in ms.
     * @return array{ran: int, failed: int, skipped: int}
     */
    public function runDue(DateTimeImmutable $now, callable $commandRunner, ?callable $onResult = null): array
    {
        $summary = ['ran' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($this->dueTasks($now) as $task) {
            $lockKey = null;
            $lockToken = null;
            if ($task->shouldRunWithoutOverlapping() && $this->cache !== null) {
                if ($task->isAutoNamed()) {
                    // Positional auto-names ('closure-N') shift when tasks are
                    // added/removed between deploys — and the lock identity
                    // shifts with them, silently dropping overlap protection.
                    $this->log('notice', sprintf(
                        "Task '%s' uses withoutOverlapping() with an auto-generated name; call ->name() to give its lock a stable identity across deploys.",
                        $task->getName()
                    ));
                }

                // Owner token: the lock value identifies THIS run, so the
                // finally-release below can never delete a successor's lock
                // (acquired after this run outlived its TTL).
                $lockKey = 'schedule.lock.' . sha1($task->getName());
                $lockToken = bin2hex(random_bytes(8));
                if (!$this->cache->add($lockKey, $lockToken, $task->getLockTtl())) {
                    $summary['skipped']++;
                    $this->log('info', sprintf("Skipped '%s': overlap lock held by a previous run.", $task->getName()));
                    if ($onResult !== null) {
                        $onResult($task, 'skipped', null, 0.0);
                    }
                    continue;
                }
            }

            $start = microtime(true);
            try {
                $result = $task->run($commandRunner);
                if (is_int($result) && $result !== 0) {
                    throw new RuntimeException(sprintf(
                        "Command '%s' exited with code %d.",
                        (string) $task->getSignature(),
                        $result
                    ));
                }

                $durationMs = (microtime(true) - $start) * 1000;
                $summary['ran']++;
                $this->log('info', sprintf("Ran '%s' in %.1f ms.", $task->getName(), $durationMs));
                if ($onResult !== null) {
                    $onResult($task, 'ran', null, $durationMs);
                }
            } catch (Throwable $e) {
                $durationMs = (microtime(true) - $start) * 1000;
                $summary['failed']++;
                $this->log('error', sprintf("Task '%s' failed: %s", $task->getName(), $e->getMessage()));
                if ($onResult !== null) {
                    $onResult($task, 'failed', $e, $durationMs);
                }
            } finally {
                // $lockKey is only ever set when a cache is present. Release
                // is owner-scoped: only delete the lock while it still holds
                // THIS run's token — if the run outlived its TTL and another
                // run re-acquired the key, that successor's lock must survive.
                if ($lockKey !== null && $this->cache !== null && $this->cache->get($lockKey) === $lockToken) {
                    $this->cache->forget($lockKey);
                }
            }
        }

        return $summary;
    }

    /**
     * Best-effort logging — a broken log channel must never break a run.
     *
     * @param 'info'|'notice'|'error' $level
     */
    private function log(string $level, string $message): void
    {
        try {
            $this->logger?->{$level}($message);
        } catch (Throwable) {
            // Intentionally ignored.
        }
    }
}
