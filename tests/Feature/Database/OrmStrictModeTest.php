<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Ions\Foundation\Kernel;
use IonsFixture\Models\Widget;

/*
|--------------------------------------------------------------------------
| ORM strict mode (Phase 10.6)
|--------------------------------------------------------------------------
| DatabaseProvider::boot() sets the Eloquent class statics EVERY boot with
| the computed value (self-correcting across tests/workers):
|
|   strict = APP_DEBUG && config('database.strict', true)
|   Model::preventLazyLoading(strict)
|   Model::preventSilentlyDiscardingAttributes(strict)
|
| Debug on  -> lazy relation access throws LazyLoadingViolationException
|              naming the relation; silently discarded fills throw too.
| Debug off -> ALWAYS relaxed, regardless of database.strict.
*/

afterEach(function () {
    putenv('APP_DEBUG');
    unset($_ENV['APP_DEBUG']);
});

function createStrictModeTables(): void
{
    $schema = Kernel::app()->get('db')->connection()->getSchemaBuilder();

    $schema->dropIfExists('parts');
    $schema->dropIfExists('widgets');

    $schema->create('widgets', function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id');
        $t->string('name');
    });
    $schema->create('parts', function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id');
        $t->unsignedInteger('widget_id');
        $t->string('name');
    });

    // TWO rows on purpose: Illuminate only arms the per-instance violation
    // flag for models hydrated in a MULTI-model result set (Builder::hydrate
    // sets it when count > 1) — a lazy load off a single first() is not an
    // N+1 and never throws upstream.
    Widget::query()->create(['name' => 'gear']);
    Widget::query()->create(['name' => 'cog']);
}

test('lazy relation access in debug throws LazyLoadingViolationException naming the relation', function () {
    putenv('APP_DEBUG=true');
    $_ENV['APP_DEBUG'] = 'true';
    bootFixtureKernel();
    createStrictModeTables();

    expect(Model::preventsLazyLoading())->toBeTrue()
        ->and(Model::preventsSilentlyDiscardingAttributes())->toBeTrue();

    $widget = Widget::query()->get()->first();

    expect(fn () => $widget->parts)->toThrow(LazyLoadingViolationException::class, 'parts');
});

test('eager loading still works in debug strict mode', function () {
    putenv('APP_DEBUG=true');
    $_ENV['APP_DEBUG'] = 'true';
    bootFixtureKernel();
    createStrictModeTables();

    $widget = Widget::query()->with('parts')->first();

    expect($widget->parts->count())->toBe(0);
});

test('debug off never throws on lazy access, regardless of database.strict', function () {
    // No APP_DEBUG -> production: statics are explicitly set back to false.
    bootFixtureKernel();
    createStrictModeTables();

    expect(Model::preventsLazyLoading())->toBeFalse()
        ->and(Model::preventsSilentlyDiscardingAttributes())->toBeFalse();

    $widget = Widget::query()->get()->first();

    expect($widget->parts->count())->toBe(0);
});

test('database.strict => false is an escape hatch even in debug', function () {
    putenv('APP_DEBUG=true');
    $_ENV['APP_DEBUG'] = 'true';
    bootFixtureKernel();

    // Re-run the provider boot with the escape hatch set — boot is
    // idempotent and re-computes the statics from the current config.
    config(['database.strict' => false]);
    (new \Ions\Providers\DatabaseProvider(Kernel::app()))->boot();

    createStrictModeTables();

    expect(Model::preventsLazyLoading())->toBeFalse();

    $widget = Widget::query()->get()->first();

    expect($widget->parts->count())->toBe(0);
});
