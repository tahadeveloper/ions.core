<?php

declare(strict_types=1);

use Illuminate\Queue\QueueManager;
use Ions\Foundation\Kernel;
use Ions\Support\Queue;
use Ions\Testing\Fakes\QueueFake;
use IonsFixture\RecordingJob;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function () {
    bootFixtureKernel();
    RecordingJob::$ran = [];
});

/** A job class that is never dispatched — used to exercise failure messages. */
final class QueueFakeTestNeverJob
{
}

test('Queue::fake() swaps the container binding and returns the fake', function () {
    $fake = Queue::fake();

    expect($fake)->toBeInstanceOf(QueueFake::class)
        ->and(Kernel::app()->get('queue'))->toBe($fake)
        ->and($fake)->toBeInstanceOf(QueueManager::class);
});

test('dispatch() routes through the fake: the job is recorded, not run', function () {
    $fake = Queue::fake();

    dispatch(new RecordingJob('intercepted'));

    $fake->assertDispatched(RecordingJob::class);
    expect(RecordingJob::$ran)->toBe([]);
});

test('assertDispatched accepts a filter callable receiving the job', function () {
    $fake = Queue::fake();

    dispatch(new RecordingJob('alpha'));

    $payload = fn (RecordingJob $job): string => (string) (new ReflectionProperty(RecordingJob::class, 'value'))->getValue($job);

    $fake->assertDispatched(RecordingJob::class, fn (RecordingJob $job) => $payload($job) === 'alpha');

    // dispatched-but-unmatched is distinguished from never-dispatched
    expect(fn () => $fake->assertDispatched(RecordingJob::class, fn (RecordingJob $job) => $payload($job) === 'beta'))
        ->toThrow(
            AssertionFailedError::class,
            'Job [IonsFixture\RecordingJob] was dispatched 1 time(s), but no dispatched job matched the given filter.'
        );
});

test('assertDispatched accepts an int as a count', function () {
    $fake = Queue::fake();

    dispatch(new RecordingJob('one'));
    dispatch(new RecordingJob('two'));

    $fake->assertDispatched(RecordingJob::class, 2);
});

test('assertDispatched fails with an Ions-worded message when nothing was dispatched at all', function () {
    $fake = Queue::fake();

    expect(fn () => $fake->assertDispatched(RecordingJob::class))
        ->toThrow(
            AssertionFailedError::class,
            'Expected job [IonsFixture\RecordingJob] to be dispatched, but it was not. No jobs were dispatched at all.'
        );
});

test('assertDispatched failures list the job classes that were dispatched instead', function () {
    $fake = Queue::fake();

    dispatch(new RecordingJob('other'));

    expect(fn () => $fake->assertDispatched(QueueFakeTestNeverJob::class))
        ->toThrow(
            AssertionFailedError::class,
            'Expected job [QueueFakeTestNeverJob] to be dispatched, but it was not. Dispatched jobs: [IonsFixture\RecordingJob].'
        );
});

test('assertDispatchedTimes passes and fails on the exact count with an Ions-worded message', function () {
    $fake = Queue::fake();

    dispatch(new RecordingJob('a'));
    dispatch(new RecordingJob('b'));

    $fake->assertDispatchedTimes(RecordingJob::class, 2);

    expect(fn () => $fake->assertDispatchedTimes(RecordingJob::class, 3))
        ->toThrow(
            AssertionFailedError::class,
            'Expected job [IonsFixture\RecordingJob] to be dispatched 3 time(s), but it was dispatched 2 time(s).'
        );

    expect(fn () => $fake->assertDispatchedTimes(QueueFakeTestNeverJob::class, 1))
        ->toThrow(
            AssertionFailedError::class,
            'Expected job [QueueFakeTestNeverJob] to be dispatched 1 time(s), but it was never dispatched. Dispatched jobs: [IonsFixture\RecordingJob].'
        );
});

test('assertNotDispatched passes when absent and fails when present', function () {
    $fake = Queue::fake();

    $fake->assertNotDispatched(RecordingJob::class);

    dispatch(new RecordingJob('x'));

    expect(fn () => $fake->assertNotDispatched(RecordingJob::class))
        ->toThrow(
            AssertionFailedError::class,
            'Expected job [IonsFixture\RecordingJob] not to be dispatched, but it was dispatched 1 time(s).'
        );

    expect(fn () => $fake->assertNotDispatched(RecordingJob::class, fn () => true))
        ->toThrow(
            AssertionFailedError::class,
            'Expected no dispatched job [IonsFixture\RecordingJob] to match the given filter, but 1 of 1 did.'
        );
});

test('assertNothingDispatched passes when quiet and fails listing what was dispatched', function () {
    $fake = Queue::fake();

    $fake->assertNothingDispatched();

    dispatch(new RecordingJob('x'));

    expect(fn () => $fake->assertNothingDispatched())
        ->toThrow(
            AssertionFailedError::class,
            'Expected no jobs to be dispatched, but some were. Dispatched jobs: [IonsFixture\RecordingJob].'
        );
});

test('the Ions assertions chain by returning the fake', function () {
    $fake = Queue::fake();

    expect($fake->assertNothingDispatched())->toBe($fake);

    dispatch(new RecordingJob('chain'));

    expect($fake->assertDispatched(RecordingJob::class))->toBe($fake)
        ->and($fake->assertDispatched(RecordingJob::class, 1))->toBe($fake)
        ->and($fake->assertDispatchedTimes(RecordingJob::class, 1))->toBe($fake)
        ->and($fake->assertNotDispatched(QueueFakeTestNeverJob::class))->toBe($fake);
});

test('assertions are reachable statically through the facade', function () {
    Queue::fake();

    dispatch(new RecordingJob('via-facade'));

    Queue::assertDispatched(RecordingJob::class);
    Queue::assertDispatchedTimes(RecordingJob::class, 1);

    expect(fn () => Queue::assertNothingDispatched())
        ->toThrow(AssertionFailedError::class);
});

test('static assertions without an installed fake throw a helpful error', function () {
    expect(fn () => Queue::assertDispatched(RecordingJob::class))
        ->toThrow(RuntimeException::class, 'Queue::fake()');
});

test('a missing-fake failure does not lazily build the real queue manager', function () {
    expect(fn () => Queue::assertDispatched(RecordingJob::class))
        ->toThrow(RuntimeException::class, 'Queue::fake()')
        ->and(Kernel::app()->resolved('queue'))->toBeFalse();
});
