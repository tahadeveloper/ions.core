<?php

declare(strict_types=1);

use Ions\Bundles\Path;
use Ions\commands\ServeCommand;
use Ions\Console\Kernel;

/*
|--------------------------------------------------------------------------
| ions serve (Phase 10.8)
|--------------------------------------------------------------------------
| `serve` wraps `php -S {host}:{port} -t public`. The command-line builder
| is a public method tested directly; the execution path (passthru) binds a
| port and is exercised manually only.
*/

afterEach(function () {
    \Ions\Foundation\Kernel::resetForTesting();
});

test('the console kernel registers serve, down and up', function () {
    $kernel = Kernel::boot(__DIR__ . '/../../fixtures/app');
    $app = $kernel->getApplication();

    expect($app->has('serve'))->toBeTrue()
        ->and($app->has('down'))->toBeTrue()
        ->and($app->has('up'))->toBeTrue();
});

test('serverCommand() builds the php -S line against public/ with defaults', function () {
    bootFixtureKernel();

    $cmd = (new ServeCommand())->serverCommand('127.0.0.1', '8000');

    expect($cmd)->toBe([PHP_BINARY, '-S', '127.0.0.1:8000', '-t', Path::root('public')]);
});

test('serverCommand() honours custom host and port', function () {
    bootFixtureKernel();

    $cmd = (new ServeCommand())->serverCommand('0.0.0.0', '9090');

    expect($cmd[2])->toBe('0.0.0.0:9090');
});
