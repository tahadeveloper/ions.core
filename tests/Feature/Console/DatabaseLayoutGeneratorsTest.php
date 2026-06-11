<?php

/**
 * make:seeder / make:schema against the host-root database/ layout (Phase
 * 11.1). Both generators resolve their target directory through
 * Path::database(), so on a 4.4 host they must land in database/seeders and
 * database/schemas; on a legacy host the {app|src}/Database/{Seeders,Schema}
 * targets stay byte-identical.
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

test('make:schema generates into database/schemas on a host-root database/ layout', function () {
    $base = makeGeneratorLayoutHost(['database']);

    try {
        Path::setBasePath($base);

        $tester = runConsoleCommand(new SchemaCommand(), ['name' => 'WidgetSchema']);

        $created = glob($base . '/database/schemas/*_WidgetSchema.php');

        expect($tester->getStatusCode())->toBe(0)
            ->and($created)->toHaveCount(1);
    } finally {
        removeGeneratorLayoutHost($base);
    }
});
