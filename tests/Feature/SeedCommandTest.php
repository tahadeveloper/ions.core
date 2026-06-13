<?php

use Illuminate\Console\Application as ConsoleApp;
use Illuminate\Events\Dispatcher;
use Ions\Bundles\Path;
use Ions\Foundation\Kernel;
use Ions\Support\DB;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(fn () => bootFixtureKernel());

/**
 * Build a minimal Illuminate Console Application wired to the Ions container.
 * Mirrors MigrateCommandTest::buildConsoleApp().
 */
function buildSeedConsoleApp(): ConsoleApp
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

/**
 * Create a throwaway host root whose seeders directory contains $files
 * (name => php source). $seedDir is relative to the host root, e.g.
 * 'database/seeders' (new layout) or 'src/Database/Seeders' (legacy).
 *
 * @param array<string, string> $files
 */
function makeSeedHost(string $seedDir, array $files): string
{
    $base = sys_get_temp_dir() . '/ions-seed-' . bin2hex(random_bytes(4));
    $dir = $base . '/' . $seedDir;
    mkdir($dir, 0777, true);

    foreach ($files as $name => $source) {
        file_put_contents($dir . '/' . $name, $source);
    }

    return $base;
}

function removeSeedHost(string $base): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $path) {
        $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    }
    rmdir($base);
}

function makeSeedTargetTable(): void
{
    $schema = Kernel::app()->get('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('seed_targets');
    $schema->create('seed_targets', function (\Illuminate\Database\Schema\Blueprint $table) {
        $table->id();
        $table->string('name');
    });
}

test('db:seed --class runs a seeder whose seed() inserts a row', function () {
    makeSeedTargetTable();

    $base = makeSeedHost('database/seeders', [
        'FooSeeder.php' => <<<'PHP'
            <?php

            namespace Database\Seeders;

            use Ions\Database\Seeder;
            use Ions\Support\DB;

            class FooSeeder extends Seeder
            {
                public function seed(): void
                {
                    DB::table('seed_targets')->insert(['name' => 'foo-row']);
                }
            }
            PHP,
    ]);

    $app = buildSeedConsoleApp();
    $app->add(new SeedCommand());
    $tester = new CommandTester($app->find('db:seed'));

    try {
        Path::setBasePath($base);
        $tester->execute(['--class' => 'FooSeeder']);

        expect($tester->getStatusCode())->toBe(0)
            ->and($tester->getDisplay())->toContain('Database seeded successfully.')
            ->and(DB::table('seed_targets')->where('name', 'foo-row')->exists())->toBeTrue();
    } finally {
        Path::resetBasePath();
        removeSeedHost($base);
    }
});

test('db:seed runs a seeder that defines run() instead of seed() (Laravel style)', function () {
    makeSeedTargetTable();

    $base = makeSeedHost('database/seeders', [
        'RunSeeder.php' => <<<'PHP'
            <?php

            namespace Database\Seeders;

            use Ions\Database\Seeder;
            use Ions\Support\DB;

            class RunSeeder extends Seeder
            {
                public function run(): void
                {
                    DB::table('seed_targets')->insert(['name' => 'run-row']);
                }
            }
            PHP,
    ]);

    $app = buildSeedConsoleApp();
    $app->add(new SeedCommand());
    $tester = new CommandTester($app->find('db:seed'));

    try {
        Path::setBasePath($base);
        $tester->execute(['--class' => 'RunSeeder']);

        expect($tester->getStatusCode())->toBe(0)
            ->and(DB::table('seed_targets')->where('name', 'run-row')->exists())->toBeTrue();
    } finally {
        Path::resetBasePath();
        removeSeedHost($base);
    }
});

test('db:seed with no --class runs DatabaseSeeder', function () {
    makeSeedTargetTable();

    $base = makeSeedHost('database/seeders', [
        'DatabaseSeeder.php' => <<<'PHP'
            <?php

            namespace Database\Seeders;

            use Ions\Database\Seeder;
            use Ions\Support\DB;

            class DatabaseSeeder extends Seeder
            {
                public function seed(): void
                {
                    DB::table('seed_targets')->insert(['name' => 'default-db-row']);
                }
            }
            PHP,
    ]);

    $app = buildSeedConsoleApp();
    $app->add(new SeedCommand());
    $tester = new CommandTester($app->find('db:seed'));

    try {
        Path::setBasePath($base);
        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(0)
            ->and(DB::table('seed_targets')->where('name', 'default-db-row')->exists())->toBeTrue();
    } finally {
        Path::resetBasePath();
        removeSeedHost($base);
    }
});

test('db:seed runs child seeders through $this->call([...]) composition', function () {
    makeSeedTargetTable();

    $base = makeSeedHost('database/seeders', [
        'OneSeeder.php' => <<<'PHP'
            <?php

            namespace Database\Seeders;

            use Ions\Database\Seeder;
            use Ions\Support\DB;

            class OneSeeder extends Seeder
            {
                public function seed(): void
                {
                    DB::table('seed_targets')->insert(['name' => 'child-one']);
                }
            }
            PHP,
        'TwoSeeder.php' => <<<'PHP'
            <?php

            namespace Database\Seeders;

            use Ions\Database\Seeder;
            use Ions\Support\DB;

            class TwoSeeder extends Seeder
            {
                public function seed(): void
                {
                    DB::table('seed_targets')->insert(['name' => 'child-two']);
                }
            }
            PHP,
        // A distinct orchestrator class name (not DatabaseSeeder) so it does
        // not collide — under one PHP process require_once caches a class by
        // FQCN, and another test already declares Database\Seeders\DatabaseSeeder.
        'ParentSeeder.php' => <<<'PHP'
            <?php

            namespace Database\Seeders;

            use Ions\Database\Seeder;

            class ParentSeeder extends Seeder
            {
                public function seed(): void
                {
                    $this->call([OneSeeder::class, TwoSeeder::class]);
                }
            }
            PHP,
    ]);

    $app = buildSeedConsoleApp();
    $app->add(new SeedCommand());
    $tester = new CommandTester($app->find('db:seed'));

    try {
        Path::setBasePath($base);
        $tester->execute(['--class' => 'ParentSeeder']);

        expect($tester->getStatusCode())->toBe(0)
            ->and(DB::table('seed_targets')->where('name', 'child-one')->exists())->toBeTrue()
            ->and(DB::table('seed_targets')->where('name', 'child-two')->exists())->toBeTrue();
    } finally {
        Path::resetBasePath();
        removeSeedHost($base);
    }
});

test('db:seed still runs a plain seeder NOT extending the base (BC)', function () {
    makeSeedTargetTable();

    // Mirror the Taskflow DemoSeeder shape: a plain class with seed(), no base.
    $base = makeSeedHost('database/seeders', [
        'PlainSeeder.php' => <<<'PHP'
            <?php

            namespace Database\Seeders;

            use Ions\Support\DB;

            class PlainSeeder
            {
                public function seed(): void
                {
                    DB::table('seed_targets')->insert(['name' => 'plain-row']);
                }
            }
            PHP,
    ]);

    $app = buildSeedConsoleApp();
    $app->add(new SeedCommand());
    $tester = new CommandTester($app->find('db:seed'));

    try {
        Path::setBasePath($base);
        $tester->execute(['--class' => 'PlainSeeder']);

        expect($tester->getStatusCode())->toBe(0)
            ->and(DB::table('seed_targets')->where('name', 'plain-row')->exists())->toBeTrue();
    } finally {
        Path::resetBasePath();
        removeSeedHost($base);
    }
});

test('db:seed returns FAILURE with a helpful message when the seeder is missing', function () {
    $base = makeSeedHost('database/seeders', []);

    $app = buildSeedConsoleApp();
    $app->add(new SeedCommand());
    $tester = new CommandTester($app->find('db:seed'));

    try {
        Path::setBasePath($base);
        $tester->execute(['--class' => 'Nope']);

        expect($tester->getStatusCode())->toBe(1)
            ->and($tester->getDisplay())->toContain('not found');
    } finally {
        Path::resetBasePath();
        removeSeedHost($base);
    }
});
