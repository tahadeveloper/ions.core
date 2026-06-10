<?php

declare(strict_types=1);

namespace Ions\Schedule;

use Closure;
use Cron\CronExpression;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A single scheduled task: either a console command (stored by signature, run
 * through a caller-injected runner) or a PHP callable.
 *
 * Tasks are pure value objects — they never run themselves, never touch the
 * cache and never log. {@see Scheduler::runDue()} orchestrates execution,
 * overlap locking and logging; {@see self::run()} only dispatches to the
 * callable or the injected command runner so the class stays fully testable.
 *
 * The default frequency matches Laravel: every minute ('* * * * *').
 */
final class Task
{
    private string $expression = '* * * * *';

    private string $name;

    private bool $withoutOverlapping = false;

    private int $lockTtl = 3600;

    /**
     * True while the task carries a positional auto-generated name
     * ('closure-N'); cleared by an explicit {@see name()} call. The Scheduler
     * uses it to warn when such a task takes an overlap lock — positional
     * names shift between deploys, shifting the lock identity with them.
     */
    private bool $autoNamed = false;

    /**
     * @param 'command'|'callable'  $type
     * @param array<int|string, mixed> $arguments Console arguments/options for command tasks.
     */
    private function __construct(
        private readonly string $type,
        private readonly ?string $signature,
        private readonly array $arguments,
        private readonly ?Closure $callback,
        string $name,
    ) {
        $this->name = $name;
    }

    /**
     * A task that runs a console command by signature.
     *
     * @param array<int|string, mixed> $arguments
     */
    public static function command(string $signature, array $arguments = []): self
    {
        return new self('command', $signature, $arguments, null, $signature);
    }

    /**
     * A task that invokes a PHP callable.
     */
    public static function callable(callable $callback, ?string $name = null): self
    {
        return new self('callable', null, [], $callback(...), $name ?? 'closure');
    }

    // -----------------------------------------------------------------------
    // Fluent frequency helpers
    // -----------------------------------------------------------------------

    /**
     * Set a raw cron expression (validated immediately).
     */
    public function cron(string $expression): self
    {
        // Constructing the expression validates it — an invalid string throws
        // InvalidArgumentException at definition time, not at schedule:run time.
        new CronExpression($expression);

        $this->expression = $expression;

        return $this;
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function everyFiveMinutes(): self
    {
        return $this->cron('*/5 * * * *');
    }

    public function everyTenMinutes(): self
    {
        return $this->cron('*/10 * * * *');
    }

    public function everyThirtyMinutes(): self
    {
        return $this->cron('*/30 * * * *');
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    /**
     * Run once a day at the given 24h wall-clock time, e.g. '03:00'.
     */
    public function dailyAt(string $time): self
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            throw new InvalidArgumentException(
                "dailyAt() expects a 24h 'HH:MM' time, got '{$time}'."
            );
        }

        return $this->cron(sprintf('%d %d * * *', (int) $m[2], (int) $m[1]));
    }

    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }

    // -----------------------------------------------------------------------
    // Fluent state
    // -----------------------------------------------------------------------

    public function name(string $name): self
    {
        $this->name = $name;
        $this->autoNamed = false;

        return $this;
    }

    /**
     * Flag the task as carrying an auto-generated positional name (set by
     * {@see Scheduler::call()} when no explicit name is given).
     */
    public function markAutoNamed(): self
    {
        $this->autoNamed = true;

        return $this;
    }

    /**
     * Guard the task with a cache lock so overlapping runs are skipped.
     * The TTL is a safety net for crashed runs that never release the lock.
     */
    public function withoutOverlapping(int $ttlSeconds = 3600): self
    {
        $this->withoutOverlapping = true;
        $this->lockTtl = $ttlSeconds;

        return $this;
    }

    // -----------------------------------------------------------------------
    // Introspection
    // -----------------------------------------------------------------------

    public function getName(): string
    {
        return $this->name;
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function isCommand(): bool
    {
        return $this->type === 'command';
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function shouldRunWithoutOverlapping(): bool
    {
        return $this->withoutOverlapping;
    }

    public function isAutoNamed(): bool
    {
        return $this->autoNamed;
    }

    public function getLockTtl(): int
    {
        return $this->lockTtl;
    }

    // -----------------------------------------------------------------------
    // Evaluation & dispatch
    // -----------------------------------------------------------------------

    public function isDue(DateTimeImmutable $now): bool
    {
        return (new CronExpression($this->expression))->isDue($now);
    }

    public function nextRunDate(DateTimeImmutable $now): DateTimeImmutable
    {
        return DateTimeImmutable::createFromMutable(
            (new CronExpression($this->expression))->getNextRunDate($now)
        );
    }

    /**
     * Dispatch the task: callables are invoked directly; command tasks are
     * handed to the injected runner as runner(signature, arguments). The
     * runner's return value (an exit code for commands) is passed through.
     *
     * @param callable(string, array<int|string, mixed>): mixed $commandRunner
     */
    public function run(callable $commandRunner): mixed
    {
        if ($this->callback !== null) {
            return ($this->callback)();
        }

        return $commandRunner((string) $this->signature, $this->arguments);
    }
}
