<?php

use Ions\Bundles\Path;

/*
|--------------------------------------------------------------------------
| install:vue / install:assets (Phase 9.5)
|--------------------------------------------------------------------------
| Every test scaffolds into a throwaway host root (Path::setBasePath), so
| neither the fixture app nor the repo is ever written to, and no node
| tooling is required — assertions parse the GENERATED files only.
*/

function makeInstallHost(): string
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ions-install-' . bin2hex(random_bytes(4));
    mkdir($base, 0777, true);

    return $base;
}

beforeEach(function () {
    bootFixtureKernel();
    $this->host = makeInstallHost();
    Path::setBasePath($this->host);
});

afterEach(function () {
    Path::resetBasePath();
    \Ions\Support\File::deleteDirectory($this->host);
});

// ---------------------------------------------------------------------------
// install:vue
// ---------------------------------------------------------------------------

test('install:vue scaffolds package.json, vite.config.js and the resources/js entry files', function () {
    $tester = runConsoleCommand(new InstallVueCommand());

    expect($tester->getStatusCode())->toBe(0);

    $package = json_decode((string) file_get_contents($this->host . '/package.json'), true);
    expect($package)->toBeArray()
        ->and($package['name'])->toBe('ionsfixture') // slug of fixture app.name
        ->and($package['private'])->toBeTrue()
        ->and($package['scripts']['dev'])->toBe('vite')
        ->and($package['scripts']['build'])->toBe('vite build')
        ->and($package['devDependencies'])->toHaveKeys(['vue', 'vite', '@vitejs/plugin-vue']);

    $config = (string) file_get_contents($this->host . '/vite.config.js');
    expect($config)->toContain('manifest: true')
        ->and($config)->toContain("outDir: 'public/build'")
        ->and($config)->toContain('public/hot') // the inline hot-file plugin
        ->and($config)->toContain("input: 'resources/js/app.js'");

    expect((string) file_get_contents($this->host . '/resources/js/app.js'))->toContain('createApp')
        ->and((string) file_get_contents($this->host . '/resources/js/App.vue'))->toContain('<template>');
});

test('install:vue appends .gitignore entries without clobbering existing content', function () {
    file_put_contents($this->host . '/.gitignore', "vendor/\n");

    runConsoleCommand(new InstallVueCommand());

    $gitignore = (string) file_get_contents($this->host . '/.gitignore');
    expect($gitignore)->toContain("vendor/\n")
        ->and($gitignore)->toContain('node_modules/')
        ->and($gitignore)->toContain('public/build/')
        ->and($gitignore)->toContain('public/hot');
});

test('install:vue .gitignore hygiene is idempotent — a second run adds no duplicate lines', function () {
    runConsoleCommand(new InstallVueCommand());
    runConsoleCommand(new InstallVueCommand(), ['--force' => true]);

    $gitignore = (string) file_get_contents($this->host . '/.gitignore');
    expect(substr_count($gitignore, 'node_modules/'))->toBe(1)
        ->and(substr_count($gitignore, 'public/build/'))->toBe(1)
        ->and(substr_count($gitignore, 'public/hot'))->toBe(1);
});

test('install:vue refuses when a target exists, lists the conflicts and writes nothing', function () {
    file_put_contents($this->host . '/vite.config.js', 'KEEP ME');

    $tester = runConsoleCommand(new InstallVueCommand());

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('vite.config.js')
        ->and($tester->getDisplay())->toContain('--force')
        // refuse-all-or-nothing: NO partial writes happened.
        ->and(file_get_contents($this->host . '/vite.config.js'))->toBe('KEEP ME')
        ->and(file_exists($this->host . '/package.json'))->toBeFalse()
        ->and(file_exists($this->host . '/resources'))->toBeFalse()
        ->and(file_exists($this->host . '/.gitignore'))->toBeFalse();
});

test('install:vue --force overwrites existing files', function () {
    file_put_contents($this->host . '/vite.config.js', 'OLD');

    $tester = runConsoleCommand(new InstallVueCommand(), ['--force' => true]);

    expect($tester->getStatusCode())->toBe(0)
        ->and((string) file_get_contents($this->host . '/vite.config.js'))->toContain('manifest: true');
});

// ---------------------------------------------------------------------------
// install:assets
// ---------------------------------------------------------------------------

test('install:assets writes the no-build starters straight into public/assets', function () {
    $tester = runConsoleCommand(new InstallAssetsCommand());

    expect($tester->getStatusCode())->toBe(0)
        ->and((string) file_get_contents($this->host . '/public/assets/css/app.css'))->toContain('body')
        ->and((string) file_get_contents($this->host . '/public/assets/js/app.js'))->toContain('DOMContentLoaded');
});

test('install:assets refuses to overwrite without --force and allows it with', function () {
    runConsoleCommand(new InstallAssetsCommand());
    file_put_contents($this->host . '/public/assets/css/app.css', 'CUSTOM');

    $refused = runConsoleCommand(new InstallAssetsCommand());
    expect($refused->getStatusCode())->toBe(1)
        ->and($refused->getDisplay())->toContain('public/assets/css/app.css')
        ->and(file_get_contents($this->host . '/public/assets/css/app.css'))->toBe('CUSTOM');

    $forced = runConsoleCommand(new InstallAssetsCommand(), ['--force' => true]);
    expect($forced->getStatusCode())->toBe(0)
        ->and((string) file_get_contents($this->host . '/public/assets/css/app.css'))->not->toBe('CUSTOM');
});

// ---------------------------------------------------------------------------
// Console kernel registration
// ---------------------------------------------------------------------------

test('both install commands are registered in the console kernel', function () {
    $app = \Ions\Console\Kernel::boot(__DIR__ . '/../../fixtures/app')->getApplication();

    expect($app->has('install:vue'))->toBeTrue()
        ->and($app->has('install:assets'))->toBeTrue();
});
