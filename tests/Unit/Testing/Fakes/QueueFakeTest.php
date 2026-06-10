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

    expect(fn () => $fake->assertDispatched(RecordingJob::class, fn (RecordingJob $job) => $payload($job) === 'beta'))
        ->toThrow(AssertionFailedError::class);
});

test('assertDispatched accepts an int as a count', function () {
    $fake = Queue::fake();

    dispatch(new RecordingJob('one'));
    dispatch(new RecordingJob('two'));

    $fake->assertDispatched(RecordingJob::class, 2);
});

test('assertDispatched fails when the job was not dispatched', function () {
    $fake = Queue::fake();

    expect(fn () => $fake->assertDispatched(RecordingJob::class))
        ->toThrow(AssertionFailedError::class);
});

test('assertDispatchedTimes passes and fails on the exact count', function () {
    $fake = Queue::fake();

    dispatch(new RecordingJob('a'));
    dispatch(new RecordingJob('b'));

    $fake->assertDispatchedTimes(RecordingJob::class, 2);

    expect(fn () => $fake->assertDispatchedTimes(RecordingJob::class, 3))
        ->toThrow(AssertionFailedError::class);
});

test('assertNotDispatched passes when absent and fails when present', function () {
    $fake = Queue::fake();

    $fake->assertNotDispatched(RecordingJob::class);

    dispatch(new RecordingJob('x'));

    expect(fn () => $fake->assertNotDispatched(RecordingJob::class))
        ->toThrow(AssertionFailedError::class);
});

test('assertNothingDispatched passes when quiet and fails after a dispatch', function () {
    $fake = Queue::fake();

    $fake->assertNothingDispatched();

    dispatch(new RecordingJob('x'));

    expect(fn () => $fake->assertNothingDispatched())
        ->toThrow(AssertionFailedError::class);
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
