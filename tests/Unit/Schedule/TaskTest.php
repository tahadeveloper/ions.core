<?php

declare(strict_types=1);

use Ions\Schedule\Task;

// ---------------------------------------------------------------------------
// Defaults
// ---------------------------------------------------------------------------

test('a command task defaults its name to the signature and runs every minute', function () {
    $task = Task::command('emails:send', ['--force' => true]);

    expect($task->getName())->toBe('emails:send')
        ->and($task->getExpression())->toBe('* * * * *')
        ->and($task->isCommand())->toBeTrue()
        ->and($task->getSignature())->toBe('emails:send')
        ->and($task->getArguments())->toBe(['--force' => true]);
});

test('a callable task uses the provided name and is not a command', function () {
    $task = Task::callable(static fn (): bool => true, 'cleanup');

    expect($task->getName())->toBe('cleanup')
        ->and($task->isCommand())->toBeFalse()
        ->and($task->getSignature())->toBeNull();
});

// ---------------------------------------------------------------------------
// Fluent frequency helpers → cron expression mapping
// ---------------------------------------------------------------------------

test('frequency helpers map to the expected cron expressions', function (string $method, string $expression) {
    $task = Task::command('emails:send')->{$method}();

    expect($task->getExpression())->toBe($expression);
})->with([
    ['everyMinute', '* * * * *'],
    ['everyFiveMinutes', '*/5 * * * *'],
    ['everyTenMinutes', '*/10 * * * *'],
    ['everyThirtyMinutes', '*/30 * * * *'],
    ['hourly', '0 * * * *'],
    ['daily', '0 0 * * *'],
    ['weekly', '0 0 * * 0'],
    ['monthly', '0 0 1 * *'],
]);

test('cron() accepts a raw expression', function () {
    expect(Task::command('emails:send')->cron('*/10 2 * * 1')->getExpression())->toBe('*/10 2 * * 1');
});

test('cron() rejects an invalid expression', function () {
    Task::command('emails:send')->cron('not a cron');
})->throws(InvalidArgumentException::class);

test('dailyAt() parses HH:MM into a daily expression', function () {
    expect(Task::command('emails:send')->dailyAt('03:00')->getExpression())->toBe('0 3 * * *')
        ->and(Task::command('emails:send')->dailyAt('23:45')->getExpression())->toBe('45 23 * * *');
});

test('dailyAt() rejects a malformed time', function () {
    Task::command('emails:send')->dailyAt('nonsense');
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------------
// isDue / nextRunDate against a frozen now
// ---------------------------------------------------------------------------

test('isDue() evaluates the expression against a frozen now', function () {
    $daily = Task::command('emails:send')->daily();

    expect($daily->isDue(new DateTimeImmutable('2026-06-10 00:00:00')))->toBeTrue()
        ->and($daily->isDue(new DateTimeImmutable('2026-06-10 12:30:00')))->toBeFalse()
        ->and(Task::command('emails:send')->everyMinute()->isDue(new DateTimeImmutable('2026-06-10 12:30:00')))->toBeTrue();
});

test('nextRunDate() returns the next matching time', function () {
    $next = Task::command('emails:send')->dailyAt('03:00')->nextRunDate(new DateTimeImmutable('2026-06-10 12:00:00'));

    expect($next)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($next->format('Y-m-d H:i'))->toBe('2026-06-11 03:00');
});

// ---------------------------------------------------------------------------
// name() / withoutOverlapping() state
// ---------------------------------------------------------------------------

test('name() overrides the task name fluently', function () {
    expect(Task::command('emails:send')->name('mailer')->getName())->toBe('mailer');
});

test('overlap protection is off by default and stateful once enabled', function () {
    $task = Task::command('emails:send');
    expect($task->shouldRunWithoutOverlapping())->toBeFalse();

    $task->withoutOverlapping();
    expect($task->shouldRunWithoutOverlapping())->toBeTrue()
        ->and($task->getLockTtl())->toBe(3600);

    expect(Task::command('emails:send')->withoutOverlapping(120)->getLockTtl())->toBe(120);
});

// ---------------------------------------------------------------------------
// run()
// ---------------------------------------------------------------------------

test('run() invokes a callable task directly and never the command runner', function () {
    $invoked = 0;
    $runnerCalls = [];

    Task::callable(static function () use (&$invoked): void {
        $invoked++;
    }, 'inline')->run(static function (string $signature, array $arguments) use (&$runnerCalls): int {
        $runnerCalls[] = [$signature, $arguments];

        return 0;
    });

    expect($invoked)->toBe(1)->and($runnerCalls)->toBe([]);
});

test('run() hands a command task to the runner with its signature and arguments', function () {
    $runnerCalls = [];

    $result = Task::command('emails:send', ['--queue' => 'high'])->run(
        static function (string $signature, array $arguments) use (&$runnerCalls): int {
            $runnerCalls[] = [$signature, $arguments];

            return 0;
        }
    );

    expect($runnerCalls)->toBe([['emails:send', ['--queue' => 'high']]])
        ->and($result)->toBe(0);
});
