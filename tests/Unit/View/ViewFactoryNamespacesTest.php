<?php

declare(strict_types=1);

use Ions\Bundles\Path;
use Ions\View\ViewFactory;
use Twig\Loader\FilesystemLoader;

/*
|--------------------------------------------------------------------------
| ViewFactory namespace registration (Phase 9.2)
|--------------------------------------------------------------------------
| config('app.twig.paths') accepts a name => dir map: relative dirs resolve
| from the HOST ROOT (Path::root()), absolute dirs are kept as-is (vendor
| packages). Legacy numeric-list entries (namespace == value, dir under
| views/) keep their pre-4.2 behavior. A missing dir is skipped with a
| logged warning — namespace registration must never kill boot.
*/

beforeEach(fn () => bootFixtureKernel());

function namespaceLoader(ViewFactory $factory, array $paths): FilesystemLoader
{
    $env = $factory->build(sys_get_temp_dir(), $paths);
    $loader = $env->getLoader();
    expect($loader)->toBeInstanceOf(FilesystemLoader::class);

    /** @var FilesystemLoader $loader */
    return $loader;
}

test('a string key registers a host-root-relative dir under that namespace', function () {
    $loader = namespaceLoader(new ViewFactory(), ['admin' => 'views/admin']);

    expect($loader->getPaths('admin'))->toBe([Path::root('views/admin')]);
});

test('an absolute dir is registered untouched', function () {
    $loader = namespaceLoader(new ViewFactory(), ['pkg' => sys_get_temp_dir()]);

    expect($loader->getPaths('pkg'))->toBe([sys_get_temp_dir()]);
});

test('a legacy numeric entry still maps to views/{name} under namespace {name}', function () {
    $loader = namespaceLoader(new ViewFactory(), ['admin']);

    expect($loader->getPaths('admin'))->toBe([rtrim(Path::views('admin'), '/')]);
});

test('a missing namespace dir is skipped (recorded, no exception)', function () {
    $factory = new ViewFactory();
    $loader = namespaceLoader($factory, ['ghost' => 'views/does-not-exist']);

    expect($loader->getPaths('ghost'))->toBe([])
        ->and($factory->loaderErrors)->toHaveCount(1);
});

test('config(app.twig.paths) namespaces are registered on the shared environment', function () {
    // The fixture config ships 'paths' => ['admin' => 'views/admin'].
    /** @var ViewFactory $factory */
    $factory = \Ions\Foundation\Kernel::app()->get('view');
    $loader = $factory->make()->getLoader();

    /** @var FilesystemLoader $loader */
    expect($loader)->toBeInstanceOf(FilesystemLoader::class)
        ->and($loader->getPaths('admin'))->toBe([Path::root('views/admin')]);
});
