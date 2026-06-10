<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Ions\Schedule\Scheduler;
use Ions\Schedule\Task;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

function scheduleTestCache(): Repository
{
    return new Repository(new ArrayStore());
}

/** A command runner that should never be reached in callable-only tests. */
function unusedCommandRunner(): callable
{
    return static fn (string $signature, array $arguments): int => 0;
}

// ---------------------------------------------------------------------------
// Registration
// ---------------------------------------------------------------------------

test('command() and call() register fluent tasks', function () {
    $scheduler = new Scheduler();

    $command = $scheduler->command('emails:send', ['--force' => true]);
    $callable = $scheduler->call(static fn (): bool => true, 'cleanup');

    expect($command)->toBeInstanceOf(Task::class)
        ->and($callable)->toBeInstanceOf(Task::class)
        ->and($scheduler->tasks())->toBe([$command, $callable]);
});

test('unnamed closures get distinct default names', function () {
    $scheduler = new Scheduler();

    $first = $scheduler->call(static fn (): bool => true);
    $second = $scheduler->call(static fn (): bool => true);

    expect($first->getName())->not->toBe($second->getName());
});

// ---------------------------------------------------------------------------
// dueTasks()
// ---------------------------------------------------------------------------

test('dueTasks() filters to the tasks due at the given now', function () {
    $scheduler = new Scheduler();

    $due = $scheduler->command('emails:send')->everyMinute();
    $scheduler->command('reports:build')->dailyAt('03:00');

    expect($scheduler->dueTasks(new DateTimeImmutable('2026-06-10 12:30:00')))->toBe([$due]);
});

// ---------------------------------------------------------------------------
// runDue() — success, failure isolation, command runner
// ---------------------------------------------------------------------------

test('runDue() runs the due tasks and reports counts', function () {
    $scheduler = new Scheduler();
    $runs = 0;

    $scheduler->call(static function () use (&$runs): void {
        $runs++;
    }, 'due')->everyMinute();
    $scheduler->call(static function () use (&$runs): void {
        $runs++;
    }, 'not-due')->dailyAt('03:00');

    $summary = $scheduler->runDue(new DateTimeImmutable('2026-06-10 12:30:00'), unusedCommandRunner());

    expect($summary)->toBe(['ran' => 1, 'failed' => 0, 'skipped' => 0])
        ->and($runs)->toBe(1);
});

test('a failing task does not stop the next one and is logged', function () {
    $logger = new Logger('test');
    $handler = new TestHandler();
    $logger->pushHandler($handler);

    $scheduler = new Scheduler(null, $logger);
    $ran = 0;

    $scheduler->call(static function (): void {
        throw new RuntimeException('boom');
    }, 'broken')->everyMinute();
    $scheduler->call(static function () use (&$ran): void {
        $ran++;
    }, 'healthy')->everyMinute();

    $summary = $scheduler->runDue(new DateTimeImmutable('2026-06-10 12:30:00'), unusedCommandRunner());

    expect($summary)->toBe(['ran' => 1, 'failed' => 1, 'skipped' => 0])
        ->and($ran)->toBe(1)
        ->and($handler->hasErrorThatContains('broken'))->toBeTrue()
        ->and($handler->hasErrorThatContains('boom'))->toBeTrue();
});

test('runDue() hands command tasks to the injected runner', function () {
    $scheduler = new Scheduler();
    $calls = [];

    $scheduler->command('emails:send', ['--queue' => 'high'])->everyMinute();

    $summary = $scheduler->runDue(
        new DateTimeImmutable('2026-06-10 12:30:00'),
        static function (string $signature, array $arguments) use (&$calls): int {
            $calls[] = [$signature, $arguments];

            return 0;
        }
    );

    expect($calls)->toBe([['emails:send', ['--queue' => 'high']]])
        ->and($summary['ran'])->toBe(1);
});

test('a non-zero command exit counts as a failure', function () {
    $scheduler = new Scheduler();
    $scheduler->command('emails:send')->everyMinute();

    $summary = $scheduler->runDue(
        new DateTimeImmutable('2026-06-10 12:30:00'),
        static fn (string $signature, array $arguments): int => 1
    );

    expect($summary)->toBe(['ran' => 0, 'failed' => 1, 'skipped' => 0]);
});

test('runDue() reports each task through the onResult callback', function () {
    $scheduler = new Scheduler(scheduleTestCache());
    $results = [];

    $scheduler->call(static fn (): bool => true, 'ok')->everyMinute();
    $scheduler->call(static function (): void {
        throw new RuntimeException('boom');
    }, 'bad')->everyMinute();

    $scheduler->runDue(
        new DateTimeImmutable('2026-06-10 12:30:00'),
        unusedCommandRunner(),
        static function (Task $task, string $status, ?Throwable $e) use (&$results): void {
            $results[] = [$task->getName(), $status, $e?->getMessage()];
        }
    );

    expect($results)->toBe([
        ['ok', 'ran', null],
        ['bad', 'failed', 'boom'],
    ]);
});

// ---------------------------------------------------------------------------
// withoutOverlapping locks
// ---------------------------------------------------------------------------

test('a pre-seeded overlap lock skips the task and is left untouched', function () {
    $cache = scheduleTestCache();
    $scheduler = new Scheduler($cache);
    $runs = 0;

    $task = $scheduler->call(static function () use (&$runs): void {
        $runs++;
    }, 'locked')->everyMinute()->withoutOverlapping();

    $key = 'schedule.lock.' . sha1($task->getName());
    $cache->add($key, 1, 3600);

    $summary = $scheduler->runDue(new DateTimeImmutable('2026-06-10 12:30:00'), unusedCommandRunner());

    expect($summary)->toBe(['ran' => 0, 'failed' => 0, 'skipped' => 1])
        ->and($runs)->toBe(0)
        ->and($cache->get($key))->not->toBeNull();
});

test('the overlap lock is held during the run and released afterwards — even on failure', function () {
    $cache = scheduleTestCache();
    $scheduler = new Scheduler($cache);
    $lockedDuringRun = null;

    $task = $scheduler->call(static function () use ($cache, &$lockedDuringRun, &$key): void {
        $lockedDuringRun = $cache->get($key) !== null;
    }, 'guarded')->everyMinute()->withoutOverlapping(120);
    $key = 'schedule.lock.' . sha1($task->getName());

    $failing = $scheduler->call(static function (): void {
        throw new RuntimeException('boom');
    }, 'guarded-failing')->everyMinute()->withoutOverlapping();
    $failingKey = 'schedule.lock.' . sha1($failing->getName());

    $summary = $scheduler->runDue(new DateTimeImmutable('2026-06-10 12:30:00'), unusedCommandRunner());

    expect($lockedDuringRun)->toBeTrue()
        ->and($cache->get($key))->toBeNull()
        ->and($cache->get($failingKey))->toBeNull()
        ->and($summary)->toBe(['ran' => 1, 'failed' => 1, 'skipped' => 0]);
});

test('lock release is owner-scoped: a run that outlives its TTL never deletes a successor\'s lock', function () {
    $cache = scheduleTestCache();
    $scheduler = new Scheduler($cache);

    // Simulate the expiry race: while A runs, its lock TTL expires and run B
    // acquires the key with B's own token. A's finally (the release path)
    // must leave B's lock untouched.
    $task = $scheduler->call(static function () use ($cache, &$key): void {
        $cache->forget($key);                       // A's lock TTL expired mid-run…
        $cache->add($key, 'token-of-run-B', 3600);  // …and run B acquired the lock.
    }, 'long-running')->everyMinute()->withoutOverlapping(1);
    $key = 'schedule.lock.' . sha1($task->getName());

    $summary = $scheduler->runDue(new DateTimeImmutable('2026-06-10 12:30:00'), unusedCommandRunner());

    expect($summary['ran'])->toBe(1)
        ->and($cache->get($key))->toBe('token-of-run-B');
});

test('an unnamed withoutOverlapping() closure logs a notice recommending ->name()', function () {
    $logger = new Logger('test');
    $handler = new TestHandler();
    $logger->pushHandler($handler);

    $scheduler = new Scheduler(scheduleTestCache(), $logger);

    $scheduler->call(static fn (): bool => true)->everyMinute()->withoutOverlapping();
    $scheduler->call(static fn (): bool => true, 'named')->everyMinute()->withoutOverlapping();

    $scheduler->runDue(new DateTimeImmutable('2026-06-10 12:30:00'), unusedCommandRunner());

    expect($handler->hasNoticeThatContains("'closure-1'"))->toBeTrue()
        ->and($handler->hasNoticeThatContains('named'))->toBeFalse();
});

test('an unnamed closure renamed via ->name() does not trigger the auto-name notice', function () {
    $logger = new Logger('test');
    $handler = new TestHandler();
    $logger->pushHandler($handler);

    $scheduler = new Scheduler(scheduleTestCache(), $logger);
    $scheduler->call(static fn (): bool => true)->name('renamed')->everyMinute()->withoutOverlapping();

    $scheduler->runDue(new DateTimeImmutable('2026-06-10 12:30:00'), unusedCommandRunner());

    expect($handler->hasNoticeRecords())->toBeFalse();
});

test('without a cache the overlap guard degrades to running the task', function () {
    $scheduler = new Scheduler();
    $runs = 0;

    $scheduler->call(static function () use (&$runs): void {
        $runs++;
    }, 'unguarded')->everyMinute()->withoutOverlapping();

    $summary = $scheduler->runDue(new DateTimeImmutable('2026-06-10 12:30:00'), unusedCommandRunner());

    expect($summary['ran'])->toBe(1)->and($runs)->toBe(1);
});
