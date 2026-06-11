<?php

declare(strict_types=1);

use IonsFixture\Http\Controllers\Binding\WidgetsController;
use IonsFixture\Models\SluggedWidget;
use IonsFixture\Models\Widget;
use Ions\Bundles\Route;
use Ions\Foundation\Kernel;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| Route model binding end-to-end through Kernel::handle() (Phase 10.2)
|--------------------------------------------------------------------------
| Fixture controller: tests/fixtures/app/http-controllers/Binding/
| Fixture models:     IonsFixture\Models\Widget (PK key),
|                     IonsFixture\Models\SluggedWidget (routeKeyName 'slug')
|
| A Model-subclass action parameter named after a route placeholder receives
| the fetched record (404 on miss, null when nullable). A non-matching name
| keeps the pre-binding container-make behavior (new empty model).
*/

function createBindingTables(): void
{
    $schema = Kernel::app()->get('db')->connection()->getSchemaBuilder();

    $schema->dropIfExists('widgets');
    $schema->create('widgets', function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id');
        $t->string('name');
        $t->string('sku');
        $t->integer('price');
    });

    $schema->dropIfExists('slugged_widgets');
    $schema->create('slugged_widgets', function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id');
        $t->string('name');
        $t->string('slug');
    });
}

beforeEach(function () {
    bootFixtureKernel();
    createBindingTables();

    Route::get('/widgets/{widget}', WidgetsController::class . '::show');
    Route::get('/widgets-nullable/{widget}', WidgetsController::class . '::showNullable');
    Route::get('/slugged/{widget}', WidgetsController::class . '::showBySlug');
    Route::get('/widgets-fresh/{widget}', WidgetsController::class . '::fresh');
    Route::get('/closure-widgets/{widget}', fn (Widget $widget) => new Response('closure:' . $widget->name));
    Route::get('/api/widgets/{widget}', fn (Widget $widget) => new Response('api:' . $widget->name));

    // The api group runs AuthMiddleware — list the binding route as public so
    // the 404 below comes from the binding miss, not a 401. The middleware
    // stack reads this config per request, so the post-boot mutation applies.
    config(['app.auth.public_paths' => array_merge(
        (array) config('app.auth.public_paths', []),
        ['/api/widgets'],
    )]);
});

afterEach(function () {
    Kernel::resetForTesting();
});

test('a bound model is injected into a controller action end-to-end', function () {
    $gear = Widget::query()->create(['name' => 'Gear', 'sku' => 'g-1', 'price' => 5]);

    $response = Kernel::handle(Request::create('/widgets/' . $gear->id));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('widget:Gear');
});

test('a missing record renders a 404 through the standard exception handler', function () {
    $response = Kernel::handle(Request::create('/widgets/999'));

    expect($response->getStatusCode())->toBe(404);
});

test('an api binding miss renders a JSON 404 through the standard exception handler', function () {
    $response = Kernel::handle(Request::create('/api/widgets/999'));

    expect($response->getStatusCode())->toBe(404)
        ->and((string) $response->headers->get('Content-Type'))->toContain('json')
        ->and((string) $response->getContent())->toContain('No query results');
});

test('a nullable model parameter receives null on a miss instead of a 404', function () {
    $response = Kernel::handle(Request::create('/widgets-nullable/999'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('widget:none');
});

test('a model overriding getRouteKeyName() binds by that column', function () {
    SluggedWidget::query()->create(['name' => 'Cog', 'slug' => 'the-cog']);

    $response = Kernel::handle(Request::create('/slugged/the-cog'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('slugged:Cog');
});

test('closure routes get the same model binding', function () {
    $gear = Widget::query()->create(['name' => 'Gear', 'sku' => 'g-1', 'price' => 5]);

    $response = Kernel::handle(Request::create('/closure-widgets/' . $gear->id));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('closure:Gear');
});

test('a model parameter not named after the placeholder keeps the container-make behavior (empty model)', function () {
    Widget::query()->create(['name' => 'Gear', 'sku' => 'g-1', 'price' => 5]);

    $response = Kernel::handle(Request::create('/widgets-fresh/1'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('fresh:empty');
});
