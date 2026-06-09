<?php

use GO\Scheduler;
use Ions\Console\Kernel;
use Ions\Console\Schedule;
use Symfony\Component\Console\Tester\CommandTester;

afterEach(fn () => \Ions\Foundation\Kernel::resetForTesting());

test('schedule loads the host schedule file and registers jobs', function () {
    \Ions\Foundation\Kernel::boot(__DIR__ . '/../../fixtures/app');

    $scheduler = Schedule::build();

    expect($scheduler)->toBeInstanceOf(Scheduler::class)
        ->and($scheduler->getQueuedJobs())->not->toBeEmpty();
});

test('schedule:run is registered and runs the due jobs', function () {
    $app = Kernel::boot(__DIR__ . '/../../fixtures/app')->getApplication();

    expect($app->has('schedule:run'))->toBeTrue();

    $tester = new CommandTester($app->find('schedule:run'));
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(0);
});
