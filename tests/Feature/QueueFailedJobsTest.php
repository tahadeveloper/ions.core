<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use IonsFixture\BackoffJob;
use IonsFixture\FailingJob;
use IonsFixture\LimitedTriesJob;

beforeEach(function () {
    bootFixtureKernel();
    createQueueTables();
    FailingJob::$shouldFail = true;
    FailingJob::$ran = [];
});

/** Query-builder shortcut for a queue table on the test connection. */
function queueTable(string $table): \Illuminate\Database\Query\Builder
{
    return Kernel::app()->get('db')->connection()->table($table);
}

/** Run queue:work --once against the database connection. */
function workOnce(array $options = []): \Symfony\Component\Console\Tester\CommandTester
{
    return runConsoleCommand(new QueueWorkCommand(), array_merge([
        'connection' => 'database',
        '--once'     => true,
    ], $options));
}

test('a database job failing past max tries is recorded in failed_jobs exactly once', function () {
    dispatch((new FailingJob('doomed'))->onConnection('database'));

    // Attempt 1 of 2: released back onto the queue, not yet failed.
    workOnce(['--tries' => '2']);
    expect(queueTable('jobs')->count())->toBe(1)
        ->and(queueTable('failed_jobs')->count())->toBe(0);

    // Attempt 2 of 2: final failure → exactly one failed_jobs row.
    workOnce(['--tries' => '2']);
    expect(queueTable('jobs')->count())->toBe(0)
        ->and(queueTable('failed_jobs')->count())->toBe(1);

    $row = queueTable('failed_jobs')->first();
    expect($row->connection)->toBe('database')
        ->and($row->queue)->toBe('default')
        ->and($row->uuid)->not->toBe('')
        ->and($row->payload)->toContain('IonsFixture\\\\FailingJob')
        ->and($row->exception)->toContain('boom from FailingJob')
        ->and($row->failed_at)->not->toBeNull();
});

test('queue:failed lists failed jobs with their class name', function () {
    dispatch((new FailingJob('listed'))->onConnection('database'));
    workOnce(['--tries' => '1']);

    $tester = runConsoleCommand(new QueueFailedCommand());

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('IonsFixture\FailingJob')
        ->and($tester->getDisplay())->toContain('database');
});

test('queue:failed reports when there are no failed jobs', function () {
    $tester = runConsoleCommand(new QueueFailedCommand());

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('No failed jobs');
});

test('queue:retry --all re-pushes failed jobs and the retried job runs', function () {
    dispatch((new FailingJob('second-chance'))->onConnection('database'));
    workOnce(['--tries' => '1']);
    expect(queueTable('failed_jobs')->count())->toBe(1)
        ->and(queueTable('jobs')->count())->toBe(0);

    $tester = runConsoleCommand(new QueueRetryCommand(), ['--all' => true]);

    expect($tester->getStatusCode())->toBe(0)
        ->and(queueTable('failed_jobs')->count())->toBe(0)
        ->and(queueTable('jobs')->count())->toBe(1);

    // The retried job actually runs (it succeeds this time).
    FailingJob::$shouldFail = false;
    workOnce(['--tries' => '1']);

    expect(FailingJob::$ran)->toBe(['second-chance'])
        ->and(queueTable('jobs')->count())->toBe(0);
});

test('queue:retry retries a single failed job by id', function () {
    dispatch((new FailingJob('by-id'))->onConnection('database'));
    workOnce(['--tries' => '1']);

    $uuid = queueTable('failed_jobs')->value('uuid');

    $tester = runConsoleCommand(new QueueRetryCommand(), ['id' => [$uuid]]);

    expect($tester->getStatusCode())->toBe(0)
        ->and(queueTable('failed_jobs')->count())->toBe(0)
        ->and(queueTable('jobs')->count())->toBe(1);
});

test('queue:retry reports an unknown id and fails', function () {
    $tester = runConsoleCommand(new QueueRetryCommand(), ['id' => ['nope']]);

    expect($tester->getStatusCode())->not->toBe(0)
        ->and($tester->getDisplay())->toContain('nope');
});

test('queue:forget deletes a single failed job', function () {
    dispatch((new FailingJob('forgotten'))->onConnection('database'));
    workOnce(['--tries' => '1']);

    $uuid = queueTable('failed_jobs')->value('uuid');

    $tester = runConsoleCommand(new QueueForgetCommand(), ['id' => $uuid]);
    expect($tester->getStatusCode())->toBe(0)
        ->and(queueTable('failed_jobs')->count())->toBe(0);

    // Unknown id → failure exit.
    $tester = runConsoleCommand(new QueueForgetCommand(), ['id' => 'nope']);
    expect($tester->getStatusCode())->not->toBe(0);
});

test('queue:flush deletes all failed jobs', function () {
    dispatch((new FailingJob('a'))->onConnection('database'));
    dispatch((new FailingJob('b'))->onConnection('database'));
    workOnce(['--tries' => '1']);
    workOnce(['--tries' => '1']);
    expect(queueTable('failed_jobs')->count())->toBe(2);

    $tester = runConsoleCommand(new QueueFlushCommand());

    expect($tester->getStatusCode())->toBe(0)
        ->and(queueTable('failed_jobs')->count())->toBe(0);
});

test('a released job honours the $backoff property via available_at', function () {
    dispatch((new BackoffJob())->onConnection('database'));

    $before = time();
    workOnce(); // BackoffJob declares $tries = 2 → first failure releases it.

    // Released back to the jobs table with the next attempt delayed ~30s.
    $row = queueTable('jobs')->first();
    expect(queueTable('failed_jobs')->count())->toBe(0)
        ->and($row)->not->toBeNull()
        ->and((int) $row->available_at)->toBeGreaterThanOrEqual($before + 29)
        ->and((int) $row->available_at)->toBeLessThanOrEqual(time() + 31);
});

test('the --backoff CLI option delays releases when the job declares none', function () {
    dispatch((new FailingJob('cli-backoff'))->onConnection('database'));

    $before = time();
    workOnce(['--tries' => '2', '--backoff' => '15']);

    $row = queueTable('jobs')->first();
    expect($row)->not->toBeNull()
        ->and((int) $row->available_at)->toBeGreaterThanOrEqual($before + 14)
        ->and((int) $row->available_at)->toBeLessThanOrEqual(time() + 16);
});

test('the $tries property on the job class wins over the CLI --tries value', function () {
    dispatch((new LimitedTriesJob())->onConnection('database'));

    // CLI says 99 tries, but the job declares $tries = 2 → failed after two runs.
    workOnce(['--tries' => '99']);
    expect(queueTable('jobs')->count())->toBe(1)
        ->and(queueTable('failed_jobs')->count())->toBe(0);

    workOnce(['--tries' => '99']);
    expect(queueTable('jobs')->count())->toBe(0)
        ->and(queueTable('failed_jobs')->count())->toBe(1);
});
