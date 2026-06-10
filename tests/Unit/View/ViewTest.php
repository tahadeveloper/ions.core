<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\View\View;

/*
|--------------------------------------------------------------------------
| Ions\View\View renderable (Phase 9.2)
|--------------------------------------------------------------------------
| The fixture app's app.twig.source is sys_get_temp_dir() (see
| tests/fixtures/app/config/app.php) so each test writes a uniquely named
| template there and renders it by name.
*/

beforeEach(fn () => bootFixtureKernel());

afterEach(function () {
    foreach (glob(sys_get_temp_dir() . '/ions_view_*.twig') ?: [] as $file) {
        @unlink($file);
    }
});

function writeTempTemplate(string $content): string
{
    $name = 'ions_view_' . uniqid('');
    file_put_contents(sys_get_temp_dir() . '/' . $name . '.twig', $content);

    return $name;
}

test('render() renders the template with the bound data through the shared env', function () {
    $name = writeTempTemplate('Hello {{ who }}');

    expect(view($name, ['who' => 'ions'])->render())->toBe('Hello ions');
});

test('the environment is resolved lazily at render time, not at construction', function () {
    // Build the View BEFORE the template exists: only render() may touch Twig.
    $name = 'ions_view_' . uniqid('');
    $view = view($name, ['who' => 'late']);

    file_put_contents(sys_get_temp_dir() . '/' . $name . '.twig', 'Late {{ who }}');

    expect($view->render())->toBe('Late late');
});

test('renders reuse ONE shared Twig environment (8.1 singleton preserved)', function () {
    $name = writeTempTemplate('static');

    view($name)->render();
    $first = Kernel::app()->get('view.env');
    view($name)->render();
    $second = Kernel::app()->get('view.env');

    expect(spl_object_id($second))->toBe(spl_object_id($first))
        ->and(spl_object_id(Kernel::app()->get('view')->make()))->toBe(spl_object_id($first));
});

test('a missing template surfaces the Twig loader error at render time', function () {
    expect(fn () => view('definitely.missing')->render())
        ->toThrow(\Twig\Error\LoaderError::class);
});
