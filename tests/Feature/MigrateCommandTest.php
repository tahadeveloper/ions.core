<?php

use Illuminate\Console\Application as ConsoleApp;
use Illuminate\Events\Dispatcher;
use Ions\Foundation\Kernel;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(fn () => bootFixtureKernel());

/**
 * Build a minimal Illuminate Console Application wired to the Ions container.
 *
 * Illuminate\Console\Command calls $this->laravel->runningUnitTests() and
 * $this->laravel->make() during execution.  The Ions container satisfies make()
 * but not runningUnitTests(), so we wrap it in a thin Illuminate Container proxy.
 */
function buildConsoleApp(): ConsoleApp
{
    $ionApp = Kernel::app();

    /** @var \Illuminate\Contracts\Container\Container $proxy */
    $proxy = new class ($ionApp) extends \Illuminate\Container\Container {
        private \Ions\Container\Container $inner;

        public function __construct(\Ions\Container\Container $inner)
        {
            $this->inner = $inner;
        }

        /** @param array<string, mixed> $parameters */
        public function make($abstract, array $parameters = []): mixed
        {
            return $this->inner->make($abstract, $parameters);
        }

        public function bound($abstract): bool
        {
            return $this->inner->has($abstract);
        }

        /**
         * @param callable|array{object, string} $callback
         * @param array<string, mixed> $parameters
         */
        public function call($callback, array $parameters = [], $defaultMethod = null): mixed
        {
            if (is_array($callback)) {
                return $callback[0]->{$callback[1]}();
            }
            return ($callback)();
        }

        public function runningUnitTests(): bool
        {
            return true;
        }
    };

    return new ConsoleApp($proxy, new Dispatcher(), '1.0');
}

test('migrate --install creates the migrations table', function () {
    $schema = Kernel::app()->get('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('migrations');

    $app = buildConsoleApp();
    $app->add(new MigrateCommand());

    $tester = new CommandTester($app->find('migrate'));
    $tester->execute(['--install' => true]);

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Migrations table created successfully.')
        ->and($schema->hasTable('migrations'))->toBeTrue();
});

test('migrate --install is idempotent when table already exists', function () {
    $schema = Kernel::app()->get('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('migrations');

    $app = buildConsoleApp();
    $app->add(new MigrateCommand());

    // First run: creates the table.
    $tester = new CommandTester($app->find('migrate'));
    $tester->execute(['--install' => true]);
    expect($schema->hasTable('migrations'))->toBeTrue();

    // Second run: table already exists — command should report so and NOT error.
    $tester->execute(['--install' => true]);
    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Migrations table exits.');
});
