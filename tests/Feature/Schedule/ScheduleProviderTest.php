<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\Schedule\Scheduler;
use Ions\Support\Request;
use Ions\Support\Schedule;
use IonsFixture\Schedule\LegacySchedule;
use IonsFixture\Schedule\NewStyleSchedule;

beforeEach(function () {
    NewStyleSchedule::reset();
    LegacySchedule::reset();
    bootFixtureKernel();
});

test("the 'schedule' binding is registered but stays unresolved on a normal request", function () {
    expect(Kernel::app()->bound('schedule'))->toBeTrue();

    Kernel::handle(Request::create('/ping'));

    expect(Kernel::app()->resolved('schedule'))->toBeFalse();
});

test("resolving 'schedule' returns a Scheduler singleton, aliased by class", function () {
    $scheduler = Kernel::app()->get('schedule');

    expect($scheduler)->toBeInstanceOf(Scheduler::class)
        ->and(Kernel::app()->get('schedule'))->toBe($scheduler)
        ->and(Kernel::app()->get(Scheduler::class))->toBe($scheduler);
});

test('the provider boots a new-style host schedule class on first resolve', function () {
    config(['app.schedule_class' => NewStyleSchedule::class]);

    /** @var Scheduler $scheduler */
    $scheduler = Kernel::app()->get('schedule');

    expect(NewStyleSchedule::$booted)->toBeTrue()
        ->and($scheduler->tasks())->toHaveCount(1)
        ->and($scheduler->tasks()[0]->getName())->toBe('fixture-task');
});

test('a legacy zero-parameter boot() is NOT invoked by the provider', function () {
    config(['app.schedule_class' => LegacySchedule::class]);

    /** @var Scheduler $scheduler */
    $scheduler = Kernel::app()->get('schedule');

    expect(LegacySchedule::$runs)->toBe(0)
        ->and($scheduler->tasks())->toBe([]);
});

test('a missing host schedule class leaves the scheduler empty', function () {
    config(['app.schedule_class' => 'IonsFixture\\Schedule\\DoesNotExist']);

    /** @var Scheduler $scheduler */
    $scheduler = Kernel::app()->get('schedule');

    expect($scheduler->tasks())->toBe([]);
});

test('the Schedule facade proxies command()/call()/tasks() to the shared scheduler', function () {
    $command = Schedule::command('emails:send')->daily();
    $callable = Schedule::call(static fn (): bool => true, 'cleanup')->everyFiveMinutes();

    expect(Schedule::tasks())->toBe([$command, $callable])
        ->and(Kernel::app()->get('schedule')->tasks())->toBe([$command, $callable])
        ->and($command->getExpression())->toBe('0 0 * * *')
        ->and($callable->getExpression())->toBe('*/5 * * * *');
});
