<?php

/**
 * Phase 8.1 — optimize / optimize:clear / preload:generate.
 */

use Ions\commands\ConfigCacheCommand;
use Ions\commands\ConfigClearCommand;
use Ions\commands\OptimizeClearCommand;
use Ions\commands\OptimizeCommand;
use Ions\commands\PreloadGenerateCommand;
use Ions\commands\RouteCacheCommand;
use Ions\commands\RouteClearCommand;
use Symfony\Component\Console\Tester\CommandTester;

function optimizeFixture(): string
{
    return dirname(__DIR__, 2) . '/fixtures/app-cache';
}

/**
 * Build one console application carrying all cache commands so that
 * OptimizeCommand's $this->call('route:cache') / call('config:cache') resolve.
 */
function runOptimizeCommand(\Illuminate\Console\Command $command): CommandTester
{
    $proxy = new \Ions\Console\ConsoleContainerProxy(\Ions\Foundation\Kernel::app());
    $app = new \Illuminate\Console\Application($proxy, new \Illuminate\Events\Dispatcher(), '1.0');
    foreach ([
        new RouteCacheCommand(),
        new RouteClearCommand(),
        new ConfigCacheCommand(),
        new ConfigClearCommand(),
        new PreloadGenerateCommand(),
        $command,
    ] as $cmd) {
        $app->add($cmd);
    }

    $tester = new CommandTester($app->find($command->getName() ?? ''));
    $tester->execute([]);

    return $tester;
}

function clearOptimizeArtifacts(): void
{
    $fx = optimizeFixture();
    foreach ([
        $fx . '/var/cache/config.php',
        $fx . '/var/cache/preload.php',
        $fx . '/var/cache/routes/web.php',
        $fx . '/var/cache/routes/api.php',
    ] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($fx . '/var/cache/routes')) {
        rmdir($fx . '/var/cache/routes');
    }
}

beforeEach(fn () => clearOptimizeArtifacts());
afterEach(fn () => clearOptimizeArtifacts());

test('optimize builds the route and config caches in one shot', function () {
    $fx = optimizeFixture();
    bootFixtureKernel($fx);

    $tester = runOptimizeCommand(new OptimizeCommand());

    expect($tester->getStatusCode())->toBe(0)
        ->and(is_file($fx . '/var/cache/routes/web.php'))->toBeTrue()
        ->and(is_file($fx . '/var/cache/routes/api.php'))->toBeTrue()
        ->and(is_file($fx . '/var/cache/config.php'))->toBeTrue()
        ->and($tester->getDisplay())->toContain('route')
        ->and($tester->getDisplay())->toContain('config');
});

test('optimize propagates a route:cache failure (closure routes)', function () {
    bootFixtureKernel(); // main fixture: closure routes

    $tester = runOptimizeCommand(new OptimizeCommand());

    expect($tester->getStatusCode())->not->toBe(0);
});

test('optimize:clear removes route, config and twig caches', function () {
    $fx = optimizeFixture();
    bootFixtureKernel($fx);
    runOptimizeCommand(new OptimizeCommand());

    // Simulate a compiled twig cache dir.
    @mkdir($fx . '/var/cache/twig/ab', 0775, true);
    file_put_contents($fx . '/var/cache/twig/ab/template.php', '<?php // compiled twig');

    $tester = runOptimizeCommand(new OptimizeClearCommand());

    expect($tester->getStatusCode())->toBe(0)
        ->and(is_file($fx . '/var/cache/routes/web.php'))->toBeFalse()
        ->and(is_file($fx . '/var/cache/config.php'))->toBeFalse()
        ->and(is_dir($fx . '/var/cache/twig'))->toBeFalse();
});

test('preload:generate emits an opcache preload file listing hot framework classes', function () {
    $fx = optimizeFixture();
    bootFixtureKernel($fx);

    $tester = runOptimizeCommand(new PreloadGenerateCommand());
    $file = $fx . '/var/cache/preload.php';

    expect($tester->getStatusCode())->toBe(0)
        ->and(is_file($file))->toBeTrue();

    $content = (string) file_get_contents($file);
    expect($content)->toContain('opcache_compile_file')
        ->and($content)->toContain('Foundation/Kernel.php')
        ->and($content)->toContain('Container/Container.php')
        ->and($content)->toContain('Middleware/Pipeline.php');

    // The generated file must be syntactically valid PHP.
    $lint = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1');
    expect((string) $lint)->toContain('No syntax errors');
});
