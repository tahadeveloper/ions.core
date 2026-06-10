<?php

declare(strict_types=1);

use IonsFixture\Schedule\FailingSchedule;
use IonsFixture\Schedule\NewStyleSchedule;

beforeEach(function () {
    NewStyleSchedule::reset();
    FailingSchedule::reset();
    bootFixtureKernel();
});

// ---------------------------------------------------------------------------
// schedule:run
// ---------------------------------------------------------------------------

test('schedule:run executes the due tasks and reports a summary', function () {
    config(['app.schedule_class' => NewStyleSchedule::class]);

    $tester = runConsoleCommand(new ScheduleRunCommand());

    expect($tester->getStatusCode())->toBe(0)
        ->and(NewStyleSchedule::$runs)->toBe(1)
        ->and($tester->getDisplay())->toContain('fixture-task')
        ->and($tester->getDisplay())->toContain('Ran 1 task(s), 0 failed, 0 skipped');
});

test('schedule:run isolates failures, exits non-zero and still runs the next task', function () {
    config(['app.schedule_class' => FailingSchedule::class]);

    $tester = runConsoleCommand(new ScheduleRunCommand());

    expect($tester->getStatusCode())->toBe(1)
        ->and(FailingSchedule::$succeededRuns)->toBe(1)
        ->and($tester->getDisplay())->toContain('failing-task')
        ->and($tester->getDisplay())->toContain('Ran 1 task(s), 1 failed, 0 skipped');
});

test('schedule:run reports when nothing is due', function () {
    // Default host schedule class is absent in the fixture app — no tasks.
    config(['app.schedule_class' => 'IonsFixture\\Schedule\\DoesNotExist']);

    $tester = runConsoleCommand(new ScheduleRunCommand());

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Ran 0 task(s), 0 failed, 0 skipped');
});

test('both schedule commands are registered in the console kernel', function () {
    $app = \Ions\Console\Kernel::boot(__DIR__ . '/../../fixtures/app')->getApplication();

    expect($app->has('schedule:run'))->toBeTrue()
        ->and($app->has('schedule:list'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// schedule:list
// ---------------------------------------------------------------------------

test('schedule:list tabulates name, expression and next run', function () {
    config(['app.schedule_class' => NewStyleSchedule::class]);

    $tester = runConsoleCommand(new ScheduleListCommand());

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('fixture-task')
        ->and($tester->getDisplay())->toContain('* * * * *')
        ->and($tester->getDisplay())->toMatch('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/');
});

test('schedule:list reports an empty schedule', function () {
    config(['app.schedule_class' => 'IonsFixture\\Schedule\\DoesNotExist']);

    $tester = runConsoleCommand(new ScheduleListCommand());

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('No scheduled tasks');
});
