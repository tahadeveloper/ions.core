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
 *    per store) which is always released in finally; the TTL only matters
 *    when a run dies hard and never reaches finally;
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
        return $this->tasks[] = Task::callable($callback, $name ?? 'closure-' . (count($this->tasks) + 1));
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
            if ($task->shouldRunWithoutOverlapping() && $this->cache !== null) {
                $lockKey = 'schedule.lock.' . sha1($task->getName());
                if (!$this->cache->add($lockKey, 1, $task->getLockTtl())) {
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
                // $lockKey is only ever set when a cache is present.
                if ($lockKey !== null && $this->cache !== null) {
                    $this->cache->forget($lockKey);
                }
            }
        }

        return $summary;
    }

    /**
     * Best-effort logging — a broken log channel must never break a run.
     *
     * @param 'info'|'error' $level
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
