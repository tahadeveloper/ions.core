<?php

use Ions\Console\Kernel;
use Symfony\Component\Console\Tester\CommandTester;

afterEach(function () {
    \Ions\Foundation\Kernel::resetForTesting();
});

test('the console kernel boots and exposes the Illuminate console application', function () {
    $kernel = Kernel::boot(__DIR__ . '/../../fixtures/app');

    expect($kernel)->toBeInstanceOf(Kernel::class)
        ->and($kernel->getApplication())->toBeInstanceOf(\Illuminate\Console\Application::class);
});

test('the console kernel registers the framework commands', function () {
    $kernel = Kernel::boot(__DIR__ . '/../../fixtures/app');
    $app = $kernel->getApplication();

    expect($app->has('make:command'))->toBeTrue()
        ->and($app->has('make:key'))->toBeTrue()
        ->and($app->has('make:middleware'))->toBeTrue()
        ->and($app->has('make:service-provider'))->toBeTrue()
        ->and($app->has('route:list'))->toBeTrue()
        ->and($app->has('migrate'))->toBeTrue();
});

test('a command runs through the kernel and returns exit code 0', function () {
    $kernel = Kernel::boot(__DIR__ . '/../../fixtures/app');
    $app = $kernel->getApplication();

    $tester = new CommandTester($app->find('route:list'));
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(0);
});

test('the console kernel registers host commands from config', function () {
    $kernel = Kernel::boot(__DIR__ . '/../../fixtures/app');
    $app = $kernel->getApplication();

    // The fixture config/console.php registers a sample host command.
    expect($app->has('fixture:hello'))->toBeTrue();

    $tester = new CommandTester($app->find('fixture:hello'));
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Hello from fixture');
});

test('run returns an integer exit code for the list command', function () {
    $kernel = Kernel::boot(__DIR__ . '/../../fixtures/app');

    $exit = $kernel->run(['ions', 'list']);

    expect($exit)->toBe(0);
});
