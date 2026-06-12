<?php

/**
 * make:seeder / make:schema against the host-root database/ layout (Phase
 * 11.1). Both generators resolve their target directory through
 * Path::database(), so on a 4.6 host they must land in database/seeders and
 * database/migrations (runnable migrations; dumps live in database/schemas);
 * on a legacy host the {app|src}/Database/{Seeders,migrations} targets stay
 * byte-identical.
 */

use Ions\Bundles\Path;

function makeGeneratorLayoutHost(array $dirs): string
{
    $base = sys_get_temp_dir() . '/ions-db-generators-' . bin2hex(random_bytes(4));
    mkdir($base, 0777, true);

    foreach ($dirs as $dir) {
        mkdir($base . '/' . $dir, 0777, true);
    }

    return $base;
}

function removeGeneratorLayoutHost(string $base): void
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

/**
 * Run make:model with make:factory also registered on the same console
 * Application, so the --factory flag's $this->call('make:factory', ...)
 * delegation resolves. Mirrors runConsoleCommand() but adds both commands.
 *
 * @param array<string, mixed> $input
 */
function runMakeModelWithFactory(array $input): \Symfony\Component\Console\Tester\CommandTester
{
    $proxy = new \Ions\Console\ConsoleContainerProxy(\Ions\Foundation\Kernel::app());
    $app = new \Illuminate\Console\Application($proxy, new \Illuminate\Events\Dispatcher(), '1.0');
    $app->add(new ModelCommand());
    $app->add(new MakeFactoryCommand());

    $tester = new \Symfony\Component\Console\Tester\CommandTester($app->find('make:model'));
    $tester->execute($input);

    return $tester;
}

beforeEach(fn () => bootFixtureKernel());

afterEach(function () {
    Path::resetBasePath();
});

test('make:seeder generates into database/seeders with the Database\\Seeders namespace on a host-root database/ layout', function () {
    $base = makeGeneratorLayoutHost(['database']);

    try {
        Path::setBasePath($base);

        $tester = runConsoleCommand(new SeederCommand(), ['name' => 'WidgetSeeder']);

        $generated = $base . '/database/seeders/WidgetSeeder.php';

        expect($tester->getStatusCode())->toBe(0)
            ->and(is_file($generated))->toBeTrue()
            ->and((string) file_get_contents($generated))
            ->toContain('class WidgetSeeder')
            ->toContain('namespace Database\\Seeders;');
    } finally {
        removeGeneratorLayoutHost($base);
    }
});

test('make:seeder prints a Database\\ autoload hint on the new layout', function () {
    $base = makeGeneratorLayoutHost(['database']);

    try {
        Path::setBasePath($base);

        $tester = runConsoleCommand(new SeederCommand(), ['name' => 'WidgetSeeder']);

        expect($tester->getStatusCode())->toBe(0)
            ->and($tester->getDisplay())->toContain('"Database\\\\Seeders\\\\": "database/seeders/"');
    } finally {
        removeGeneratorLayoutHost($base);
    }
});

test('make:seeder keeps the legacy {src}/Database/Seeders target and App\\Database\\Seeders namespace when no database/ dir exists', function () {
    $base = makeGeneratorLayoutHost(['src']);

    try {
        Path::setBasePath($base);

        $tester = runConsoleCommand(new SeederCommand(), ['name' => 'WidgetSeeder']);

        $generated = $base . '/src/Database/Seeders/WidgetSeeder.php';

        expect($tester->getStatusCode())->toBe(0)
            ->and(is_file($generated))->toBeTrue()
            ->and((string) file_get_contents($generated))
            ->toContain('namespace App\\Database\\Seeders;');
    } finally {
        removeGeneratorLayoutHost($base);
    }
});

test('make:factory generates into database/factories with the Database\\Factories namespace on a host-root database/ layout', function () {
    $base = makeGeneratorLayoutHost(['database']);

    try {
        Path::setBasePath($base);

        $tester = runConsoleCommand(new MakeFactoryCommand(), ['name' => 'WidgetFactory']);

        $generated = $base . '/database/factories/WidgetFactory.php';

        expect($tester->getStatusCode())->toBe(0)
            ->and(is_file($generated))->toBeTrue()
            ->and((string) file_get_contents($generated))
            ->toContain('namespace Database\\Factories;')
            ->toContain('class WidgetFactory extends Factory');
    } finally {
        removeGeneratorLayoutHost($base);
    }
});

test('make:factory prints a Database\\ autoload hint on the new layout', function () {
    $base = makeGeneratorLayoutHost(['database']);

    try {
        Path::setBasePath($base);

        $tester = runConsoleCommand(new MakeFactoryCommand(), ['name' => 'WidgetFactory']);

        expect($tester->getStatusCode())->toBe(0)
            ->and($tester->getDisplay())->toContain('"Database\\\\Factories\\\\": "database/factories/"');
    } finally {
        removeGeneratorLayoutHost($base);
    }
});

test('make:factory keeps the legacy {src}/Factories target and App\\Factories namespace when no database/ dir exists', function () {
    $base = makeGeneratorLayoutHost(['src']);

    try {
        Path::setBasePath($base);

        $tester = runConsoleCommand(new MakeFactoryCommand(), ['name' => 'WidgetFactory']);

        $generated = $base . '/src/Factories/WidgetFactory.php';

        expect($tester->getStatusCode())->toBe(0)
            ->and(is_file($generated))->toBeTrue()
            ->and((string) file_get_contents($generated))
            ->toContain('namespace App\\Factories;');
    } finally {
        removeGeneratorLayoutHost($base);
    }
});

test('make:model generates into app/Models with the App\\Models namespace and HasIonsFactory', function () {
    $base = makeGeneratorLayoutHost(['app']);

    try {
        Path::setBasePath($base);

        $tester = runConsoleCommand(new ModelCommand(), ['name' => 'Widget']);

        $generated = $base . '/app/Models/Widget.php';

        expect($tester->getStatusCode())->toBe(0)
            ->and(is_file($generated))->toBeTrue();

        $contents = (string) file_get_contents($generated);

        expect($contents)
            ->toContain('namespace App\\Models;')
            ->toContain('class Widget extends Model')
            ->toContain('use Ions\\Database\\HasIonsFactory;')
            ->toContain('use HasIonsFactory;')
            ->toContain("protected \$table = 'widgets';")
            ->and($contents)->not->toContain('{{');

        $lint = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($generated) . ' 2>&1');
        expect((string) $lint)->toContain('No syntax errors');
    } finally {
        removeGeneratorLayoutHost($base);
    }
});

test('make:model refuses to overwrite without --force and overwrites with it', function () {
    $base = makeGeneratorLayoutHost(['app']);

    try {
        Path::setBasePath($base);

        runConsoleCommand(new ModelCommand(), ['name' => 'Widget']);

        $generated = $base . '/app/Models/Widget.php';
        file_put_contents($generated, '<?php // sentinel');

        $refused = runConsoleCommand(new ModelCommand(), ['name' => 'Widget']);

        expect($refused->getStatusCode())->toBe(1)
            ->and($refused->getDisplay())->toContain('already exists')
            ->and((string) file_get_contents($generated))->toBe('<?php // sentinel');

        $forced = runConsoleCommand(new ModelCommand(), ['name' => 'Widget', '--force' => true]);

        expect($forced->getStatusCode())->toBe(0)
            ->and((string) file_get_contents($generated))->toContain('class Widget extends Model');
    } finally {
        removeGeneratorLayoutHost($base);
    }
});

test('make:model rejects a path-traversal name and writes nothing', function () {
    $base = makeGeneratorLayoutHost(['app']);

    try {
        Path::setBasePath($base);

        $tester = runConsoleCommand(new ModelCommand(), ['name' => '../../Escaped']);

        expect($tester->getStatusCode())->toBe(1)
            ->and($tester->getDisplay())->toContain('Invalid class name')
            ->and(is_file($base . '/app/Models/../../Escaped.php'))->toBeFalse()
            ->and(is_file(dirname($base) . '/Escaped.php'))->toBeFalse();
    } finally {
        removeGeneratorLayoutHost($base);
    }
});

test('make:model --factory also generates Database\\Factories\\{Name}Factory on the new layout', function () {
    $base = makeGeneratorLayoutHost(['app', 'database']);

    try {
        Path::setBasePath($base);

        $tester = runMakeModelWithFactory(['name' => 'Gizmo', '--factory' => true]);

        $model = $base . '/app/Models/Gizmo.php';
        $factory = $base . '/database/factories/GizmoFactory.php';

        expect($tester->getStatusCode())->toBe(0)
            ->and(is_file($model))->toBeTrue()
            ->and(is_file($factory))->toBeTrue();

        expect((string) file_get_contents($factory))
            ->toContain('namespace Database\\Factories;')
            ->toContain('class GizmoFactory extends Factory')
            ->toContain('protected string $model = \\App\\Models\\Gizmo::class;');
    } finally {
        removeGeneratorLayoutHost($base);
    }
});

test('make:model with no --factory generates only the model', function () {
    $base = makeGeneratorLayoutHost(['app', 'database']);

    try {
        Path::setBasePath($base);

        $tester = runMakeModelWithFactory(['name' => 'Gizmo']);

        expect($tester->getStatusCode())->toBe(0)
            ->and(is_file($base . '/app/Models/Gizmo.php'))->toBeTrue()
            ->and(is_file($base . '/database/factories/GizmoFactory.php'))->toBeFalse();
    } finally {
        removeGeneratorLayoutHost($base);
    }
});

test('make:model --factory wires factory()->make() end to end on the new layout', function () {
    $base = makeGeneratorLayoutHost(['app', 'database']);

    // Register runtime PSR-4 loaders for the generated model + factory so the
    // booted kernel can autoload App\Models\* and Database\Factories\* from the
    // temp host. Unregistered in finally.
    $modelLoader = static function (string $class) use ($base): void {
        if (str_starts_with($class, 'App\\Models\\')) {
            $file = $base . '/app/Models/' . substr($class, strlen('App\\Models\\')) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    };
    $factoryLoader = static function (string $class) use ($base): void {
        if (str_starts_with($class, 'Database\\Factories\\Wired')) {
            $file = $base . '/database/factories/' . substr($class, strlen('Database\\Factories\\')) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    };

    spl_autoload_register($modelLoader);
    spl_autoload_register($factoryLoader);

    try {
        Path::setBasePath($base);

        $tester = runMakeModelWithFactory(['name' => 'WiredWidget', '--factory' => true]);
        expect($tester->getStatusCode())->toBe(0);

        // Give the generated factory a usable definition so make() yields data.
        $factoryFile = $base . '/database/factories/WiredWidgetFactory.php';
        $factorySrc = (string) file_get_contents($factoryFile);
        $factorySrc = str_replace(
            "return [\n            //\n        ];",
            "return [\n            'name' => 'Wired',\n        ];",
            $factorySrc
        );
        file_put_contents($factoryFile, $factorySrc);

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
        $modelClass = 'App\\Models\\WiredWidget';

        $instance = $modelClass::factory()->make();

        expect($instance)->toBeInstanceOf($modelClass)
            ->and($instance->exists)->toBeFalse()
            ->and($instance->name)->toBe('Wired');
    } finally {
        spl_autoload_unregister($modelLoader);
        spl_autoload_unregister($factoryLoader);
        removeGeneratorLayoutHost($base);
    }
});

test('make:schema generates into database/migrations on a host-root database/ layout', function () {
    $base = makeGeneratorLayoutHost(['database']);

    try {
        Path::setBasePath($base);

        $tester = runConsoleCommand(new SchemaCommand(), ['name' => 'WidgetSchema']);

        $created = glob($base . '/database/migrations/*_WidgetSchema.php');

        expect($tester->getStatusCode())->toBe(0)
            ->and($created)->toHaveCount(1);
    } finally {
        removeGeneratorLayoutHost($base);
    }
});
