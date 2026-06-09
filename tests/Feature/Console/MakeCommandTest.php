<?php

use Ions\Bundles\Path;
use Ions\Console\Kernel;
use Ions\Support\File;
use Symfony\Component\Console\Tester\CommandTester;

afterEach(function () {
    $generated = Path::src('Commands/SendReportCommand.php');
    if (File::exists($generated)) {
        File::delete($generated);
    }
    \Ions\Foundation\Kernel::resetForTesting();
});

test('make:command is registered on the console kernel', function () {
    $app = Kernel::boot(__DIR__ . '/../../fixtures/app')->getApplication();

    expect($app->has('make:command'))->toBeTrue();
});

test('make:command writes a command class from the stub', function () {
    $app = Kernel::boot(__DIR__ . '/../../fixtures/app')->getApplication();

    $generated = Path::src('Commands/SendReportCommand.php');
    if (File::exists($generated)) {
        File::delete($generated);
    }

    $tester = new CommandTester($app->find('make:command'));
    $tester->execute(['name' => 'SendReportCommand']);

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Command created successfully')
        ->and(File::exists($generated))->toBeTrue();

    $contents = File::get($generated);

    expect($contents)
        ->toContain('namespace App\\Commands;')
        ->toContain('class SendReportCommand extends Command')
        ->toContain("protected \$signature = 'app:send-report'")
        ->toContain('public function handle(): int')
        ->and($contents)->not->toContain('{{ class }}')
        ->and($contents)->not->toContain('{{ signature }}');
});

test('make:command honours an explicit --command signature', function () {
    $app = Kernel::boot(__DIR__ . '/../../fixtures/app')->getApplication();

    $generated = Path::src('Commands/SendReportCommand.php');
    if (File::exists($generated)) {
        File::delete($generated);
    }

    $tester = new CommandTester($app->find('make:command'));
    $tester->execute(['name' => 'SendReportCommand', '--command' => 'reports:send {--daily}']);

    expect($tester->getStatusCode())->toBe(0);

    $contents = File::get($generated);
    expect($contents)->toContain("protected \$signature = 'reports:send {--daily}'");
});
