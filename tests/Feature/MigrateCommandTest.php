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

/**
 * Create a throwaway host root whose migrations directory contains $files
 * (name => php source). $schemaDir is relative to the host root, e.g.
 * 'database/migrations' (new layout) or 'src/Database/migrations' (legacy).
 *
 * @param array<string, string> $files
 */
function makeMigrateHost(string $schemaDir, array $files): string
{
    $base = sys_get_temp_dir() . '/ions-migrate-' . bin2hex(random_bytes(4));
    $dir = $base . '/' . $schemaDir;
    mkdir($dir, 0777, true);

    foreach ($files as $name => $source) {
        file_put_contents($dir . '/' . $name, $source);
    }

    return $base;
}

function removeMigrateHost(string $base): void
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

// ---------------------------------------------------------------------------
// Schema discovery — host-root database/migrations (4.6) vs legacy Database/migrations
// ---------------------------------------------------------------------------

test('migrate runs schema classes from host-root database/migrations (new layout)', function () {
    $schema = Kernel::app()->get('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('migrations');
    $schema->dropIfExists('phase11_widgets');

    $base = makeMigrateHost('database/migrations', [
        '_1000_CreatePhase11Widgets.php' => <<<'PHP'
            <?php

            namespace App\Database\Schema;

            use Illuminate\Database\Schema\Blueprint;
            use Ions\Support\DB;

            class _1000_CreatePhase11Widgets
            {
                public function up(): void
                {
                    DB::connection()->getSchemaBuilder()->create('phase11_widgets', function (Blueprint $table) {
                        $table->id();
                        $table->string('name');
                    });
                }

                public function down(): void
                {
                    DB::connection()->getSchemaBuilder()->dropIfExists('phase11_widgets');
                }
            }
            PHP,
    ]);

    $app = buildConsoleApp();
    $app->add(new MigrateCommand());
    $tester = new CommandTester($app->find('migrate'));

    try {
        $tester->execute(['--install' => true]);

        \Ions\Bundles\Path::setBasePath($base);
        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(0)
            ->and($tester->getDisplay())->toContain('_1000_CreatePhase11Widgets installed successfully')
            ->and($schema->hasTable('phase11_widgets'))->toBeTrue()
            ->and(
                \Ions\Support\DB::table('migrations')->where('migration', '_1000_CreatePhase11Widgets')->first()
            )->not->toBeNull();
    } finally {
        \Ions\Bundles\Path::resetBasePath();
        removeMigrateHost($base);
    }
});

test('migrate runs anonymous-class migration stubs (return new class) from database/migrations', function () {
    $schema = Kernel::app()->get('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('migrations');
    $schema->dropIfExists('phase11_stub_jobs');

    // The shipped jobs/notifications stubs use this exact shape: a file that
    // `return new class () extends Migration` — no named class to autoload.
    $base = makeMigrateHost('database/migrations', [
        'create_phase11_stub_jobs_table.php' => <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Ions\Support\DB;

            return new class () extends Migration {
                public function up(): void
                {
                    DB::connection()->getSchemaBuilder()->create('phase11_stub_jobs', function (Blueprint $table) {
                        $table->id();
                        $table->longText('payload');
                    });
                }

                public function down(): void
                {
                    DB::connection()->getSchemaBuilder()->dropIfExists('phase11_stub_jobs');
                }
            };
            PHP,
    ]);

    $app = buildConsoleApp();
    $app->add(new MigrateCommand());
    $tester = new CommandTester($app->find('migrate'));

    try {
        $tester->execute(['--install' => true]);

        \Ions\Bundles\Path::setBasePath($base);
        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(0)
            ->and($schema->hasTable('phase11_stub_jobs'))->toBeTrue()
            ->and(
                \Ions\Support\DB::table('migrations')->where('migration', 'create_phase11_stub_jobs_table')->first()
            )->not->toBeNull();
    } finally {
        \Ions\Bundles\Path::resetBasePath();
        removeMigrateHost($base);
    }
});

test('migrate resolves an anonymous-class stub twice in one process without Class-not-found Error', function () {
    // Regression: resolveSchema() used `require_once`, which returns int(1) on
    // the SECOND inclusion of the same file in one process. An anonymous-class
    // stub (`return new class extends Migration`) then fell through to
    // `new App\Database\Schema\{basename}` and fataled "Class not found".
    // Two migrate runs in one process (e.g. refresh+migrate, workers, host
    // scripts running migrate twice) must both resolve the stub cleanly.
    $schema = Kernel::app()->get('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('migrations');
    $schema->dropIfExists('phase11_stub_twice');

    $base = makeMigrateHost('database/migrations', [
        'create_phase11_stub_twice_table.php' => <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Ions\Support\DB;

            return new class () extends Migration {
                public function up(): void
                {
                    DB::connection()->getSchemaBuilder()->create('phase11_stub_twice', function (Blueprint $table) {
                        $table->id();
                    });
                }

                public function down(): void
                {
                    DB::connection()->getSchemaBuilder()->dropIfExists('phase11_stub_twice');
                }
            };
            PHP,
    ]);

    $app = buildConsoleApp();
    $app->add(new MigrateCommand());
    $tester = new CommandTester($app->find('migrate'));

    try {
        $tester->execute(['--install' => true]);

        \Ions\Bundles\Path::setBasePath($base);

        // First run: stub resolves on FIRST inclusion (require returns the obj).
        $tester->execute([]);
        expect($tester->getStatusCode())->toBe(0)
            ->and($schema->hasTable('phase11_stub_twice'))->toBeTrue();

        // Second run in the SAME process: must NOT Error. The migration is
        // already recorded, so this run is a clean idempotent no-op — but
        // resolveSchema is reached again on a refresh-style cycle. Force a
        // resolve by clearing the migrations record so the file is loaded
        // a SECOND time in this process.
        \Ions\Support\DB::table('migrations')->where('migration', 'create_phase11_stub_twice_table')->delete();
        $schema->dropIfExists('phase11_stub_twice');

        $tester->execute([]);
        expect($tester->getStatusCode())->toBe(0)
            ->and($schema->hasTable('phase11_stub_twice'))->toBeTrue();
    } finally {
        \Ions\Bundles\Path::resetBasePath();
        removeMigrateHost($base);
    }
});

test('migrate resolves a named-class stub twice in one process without a redeclare fatal', function () {
    // Regression guard: caching the included value must NOT re-include a
    // named-class file (which would fatal "cannot redeclare class").
    $schema = Kernel::app()->get('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('migrations');
    $schema->dropIfExists('phase11_named_twice');

    $base = makeMigrateHost('database/migrations', [
        '_2000_CreatePhase11NamedTwice.php' => <<<'PHP'
            <?php

            namespace App\Database\Schema;

            use Illuminate\Database\Schema\Blueprint;
            use Ions\Support\DB;

            class _2000_CreatePhase11NamedTwice
            {
                public function up(): void
                {
                    DB::connection()->getSchemaBuilder()->create('phase11_named_twice', function (Blueprint $table) {
                        $table->id();
                    });
                }

                public function down(): void
                {
                    DB::connection()->getSchemaBuilder()->dropIfExists('phase11_named_twice');
                }
            }
            PHP,
    ]);

    $app = buildConsoleApp();
    $app->add(new MigrateCommand());
    $tester = new CommandTester($app->find('migrate'));

    try {
        $tester->execute(['--install' => true]);

        \Ions\Bundles\Path::setBasePath($base);

        $tester->execute([]);
        expect($schema->hasTable('phase11_named_twice'))->toBeTrue();

        // Second resolve in same process — must not redeclare-fatal.
        \Ions\Support\DB::table('migrations')->where('migration', '_2000_CreatePhase11NamedTwice')->delete();
        $schema->dropIfExists('phase11_named_twice');

        $tester->execute([]);
        expect($tester->getStatusCode())->toBe(0)
            ->and($schema->hasTable('phase11_named_twice'))->toBeTrue();
    } finally {
        \Ions\Bundles\Path::resetBasePath();
        removeMigrateHost($base);
    }
});

test('migrate still discovers legacy {src}/Database/migrations classes when no database/ dir exists', function () {
    $schema = Kernel::app()->get('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('migrations');
    $schema->dropIfExists('phase11_legacy_widgets');

    $base = makeMigrateHost('src/Database/migrations', [
        '_1001_CreatePhase11LegacyWidgets.php' => <<<'PHP'
            <?php

            namespace App\Database\Schema;

            use Illuminate\Database\Schema\Blueprint;
            use Ions\Support\DB;

            class _1001_CreatePhase11LegacyWidgets
            {
                public function up(): void
                {
                    DB::connection()->getSchemaBuilder()->create('phase11_legacy_widgets', function (Blueprint $table) {
                        $table->id();
                    });
                }

                public function down(): void
                {
                    DB::connection()->getSchemaBuilder()->dropIfExists('phase11_legacy_widgets');
                }
            }
            PHP,
    ]);

    $app = buildConsoleApp();
    $app->add(new MigrateCommand());
    $tester = new CommandTester($app->find('migrate'));

    try {
        $tester->execute(['--install' => true]);

        \Ions\Bundles\Path::setBasePath($base);
        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(0)
            ->and($schema->hasTable('phase11_legacy_widgets'))->toBeTrue();
    } finally {
        \Ions\Bundles\Path::resetBasePath();
        removeMigrateHost($base);
    }
});
