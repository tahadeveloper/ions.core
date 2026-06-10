<?php

/**
 * Model-factory tests — Phase 8.5e.
 *
 * Covers the minimal Ions\Database\Factory: definition defaults, make()
 * (single unsaved / count(n) collection), create() persistence through the
 * booted fixture kernel's sqlite connection, state() stacking (array +
 * callable), per-instance closure evaluation, precedence
 * (make overrides > states > definition), the HasIonsFactory trait
 * resolution convention + static $factory override, immutability of
 * count()/state(), and the lazy faker generator (require-dev decision).
 */

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Ions\Database\Factory;
use Ions\Database\HasIonsFactory;
use IonsFixture\Models\Factories\FakerWidgetFactory;
use IonsFixture\Models\Factories\WidgetFactory;
use IonsFixture\Models\Gizmo;
use IonsFixture\Models\Widget;

/** Fixture model with no matching conventional factory and no $factory override. */
class FactoryTestOrphanModel extends Model
{
    use HasIonsFactory;
}

function createWidgetsTable(): void
{
    $schema = \Ions\Foundation\Kernel::app()->get('db')->connection()->getSchemaBuilder();

    $schema->dropIfExists('widgets');
    $schema->create('widgets', function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id');
        $t->string('name');
        $t->string('sku');
        $t->integer('price');
    });
}

beforeEach(function () {
    bootFixtureKernel();
    createWidgetsTable();
});

afterEach(function () {
    \Ions\Foundation\Kernel::resetForTesting();
});

// ---------------------------------------------------------------------------
// definition() defaults + make()
// ---------------------------------------------------------------------------

test('make returns a single unsaved model with definition defaults', function () {
    $widget = WidgetFactory::new()->make();

    expect($widget)->toBeInstanceOf(Widget::class)
        ->and($widget->exists)->toBeFalse()
        ->and($widget->name)->toBe('Widget')
        ->and($widget->price)->toBe(100)
        ->and($widget->sku)->toStartWith('Widget-');
});

test('count(3)->make returns an Eloquent collection of three unsaved models', function () {
    $widgets = WidgetFactory::new()->count(3)->make();

    expect($widgets)->toBeInstanceOf(Collection::class)
        ->and($widgets)->toHaveCount(3);

    foreach ($widgets as $widget) {
        expect($widget)->toBeInstanceOf(Widget::class)
            ->and($widget->exists)->toBeFalse();
    }
});

test('definition closures are evaluated per instance', function () {
    $widgets = WidgetFactory::new()->count(2)->make();

    // sku embeds Str::random(8) — two instances must differ.
    expect($widgets[0]->sku)->not->toBe($widgets[1]->sku);
});

test('definition closures receive the partially-built attributes array', function () {
    $widget = WidgetFactory::new()->state(['name' => 'Gadget'])->make();

    // The sku closure reads $attributes['name'], which the state already changed.
    expect($widget->sku)->toStartWith('Gadget-');
});

// ---------------------------------------------------------------------------
// create()
// ---------------------------------------------------------------------------

test('create persists a single model through the fixture connection', function () {
    $widget = WidgetFactory::new()->create(['name' => 'Persisted']);

    expect($widget->exists)->toBeTrue()
        ->and($widget->id)->not->toBeNull();

    $row = \Ions\Foundation\Kernel::app()->get('db')
        ->connection()->table('widgets')->where('name', 'Persisted')->first();

    expect($row)->not->toBeNull()
        ->and($row->price)->toBe(100);
});

test('count(3)->create persists three rows', function () {
    $widgets = WidgetFactory::new()->count(3)->create();

    expect($widgets)->toBeInstanceOf(Collection::class)
        ->and($widgets)->toHaveCount(3);

    $count = \Ions\Foundation\Kernel::app()->get('db')
        ->connection()->table('widgets')->count();

    expect($count)->toBe(3);
});

// ---------------------------------------------------------------------------
// state() — array + callable, stacking, precedence
// ---------------------------------------------------------------------------

test('array state overrides definition defaults', function () {
    $widget = WidgetFactory::new()->state(['price' => 999])->make();

    expect($widget->price)->toBe(999)
        ->and($widget->name)->toBe('Widget');
});

test('callable state receives the current attributes and returns overrides', function () {
    $widget = WidgetFactory::new()
        ->state(fn (array $attributes): array => ['name' => $attributes['name'] . ' Pro'])
        ->make();

    expect($widget->name)->toBe('Widget Pro');
});

test('states stack in order, later states seeing earlier results', function () {
    $widget = WidgetFactory::new()
        ->state(['price' => 200])
        ->state(fn (array $attributes): array => ['price' => $attributes['price'] + 50])
        ->make();

    expect($widget->price)->toBe(250);
});

test('make overrides win over states which win over the definition', function () {
    $widget = WidgetFactory::new()
        ->state(['price' => 200, 'name' => 'FromState'])
        ->make(['price' => 300]);

    expect($widget->price)->toBe(300)   // make() override beats state
        ->and($widget->name)->toBe('FromState'); // state beats definition
});

test('count and state are immutable and return new factory instances', function () {
    $base = WidgetFactory::new();
    $counted = $base->count(2);
    $stated = $base->state(['price' => 1]);

    expect($counted)->not->toBe($base)
        ->and($stated)->not->toBe($base);

    // The base factory is unchanged: single default-priced model.
    $widget = $base->make();
    expect($widget)->toBeInstanceOf(Widget::class)
        ->and($widget->price)->toBe(100);
});

// ---------------------------------------------------------------------------
// HasIonsFactory trait resolution
// ---------------------------------------------------------------------------

test('Model::factory resolves {ModelNamespace}\\Factories\\{Model}Factory by convention', function () {
    $factory = Widget::factory();

    expect($factory)->toBeInstanceOf(WidgetFactory::class);

    $widget = Widget::factory()->create();
    expect($widget->exists)->toBeTrue();
});

test('a static $factory property overrides the convention', function () {
    expect(Gizmo::factory())->toBeInstanceOf(WidgetFactory::class);
});

test('factory resolution fails with a helpful message when no factory exists', function () {
    FactoryTestOrphanModel::factory();
})->throws(RuntimeException::class, 'FactoryTestOrphanModelFactory');

// ---------------------------------------------------------------------------
// Faker (require-dev; lazy + memoized)
// ---------------------------------------------------------------------------

test('the faker generator is lazy, memoized per factory, and seedable deterministically', function () {
    $a = FakerWidgetFactory::new();
    $b = FakerWidgetFactory::new();

    expect($a->faker)->toBe($a->faker)        // memoized
        ->and($a->faker)->not->toBe($b->faker); // per factory instance

    // Faker seeding is global (mt_srand), so seed immediately before each make.
    $a->faker->seed(42);
    $nameA = $a->make()->name;

    $b->faker->seed(42);
    $nameB = $b->make()->name;

    expect($nameA)->toBe($nameB);
});

test('faker-driven definitions produce differing values per instance', function () {
    $widgets = FakerWidgetFactory::new()->count(2)->make();

    expect($widgets[0]->sku)->not->toBe($widgets[1]->sku);
});

test('accessing an unknown magic property throws', function () {
    /** @phpstan-ignore-next-line intentional bad property access */
    WidgetFactory::new()->nope;
})->throws(LogicException::class);
