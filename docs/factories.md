# Model factories

`Ions\Database\Factory` is a **minimal** model factory for building Eloquent
models in tests and seeders: attribute defaults from `definition()`, unsaved
instances via `make()`, persisted ones via `create()`, batches via `count()`,
and stackable `state()` overrides. It deliberately does **not** replicate
Laravel's full factory system — see [Scope](#scope) at the bottom.

## Defining a factory

A factory names its model and returns attribute defaults from `definition()`.
Values may be plain, or closures evaluated once **per built instance** —
closures receive the partially-built attributes array (keys declared earlier
are already resolved; later keys may still be unevaluated closures):

```php
<?php

declare(strict_types=1);

namespace App\Factories;

use App\Widget;
use Ions\Database\Factory;
use Ions\Support\Str;

/**
 * @extends Factory<Widget>
 */
class WidgetFactory extends Factory
{
    protected string $model = Widget::class;

    protected function definition(): array
    {
        return [
            'name'  => 'Widget',
            'sku'   => fn (array $attributes): string => $attributes['name'] . '-' . Str::random(8),
            'price' => 100,
        ];
    }
}
```

Generate one with `php bin/ions make:factory WidgetFactory` (writes to
`{src|app}/Factories/`, inferring the model `App\Widget` from the name —
override with `--model=App\Models\Widget`).

Attributes are written with `forceFill()`, so `$fillable`/`$guarded` does not
restrict factory attributes.

## Using factories

```php
$widget  = WidgetFactory::new()->make();              // single unsaved model
$widget  = WidgetFactory::new()->create();            // persisted (save() called)
$widgets = WidgetFactory::new()->count(3)->make();    // Eloquent Collection of 3
$widgets = WidgetFactory::new()->count(3)->create();  // 3 persisted rows
```

`make()`/`create()` return a single model when count is 1 (the default) and an
`Illuminate\Database\Eloquent\Collection` otherwise.

### States and overrides

`state()` layers overrides on top of the definition and is **immutable** —
each call returns a new factory, so partially-configured factories can be
shared safely. Array states merge over the current attributes; callable states
receive the current attributes and return the overrides to merge. States stack
in order, and attributes passed directly to `make()`/`create()` win over
everything:

```php
$premium = WidgetFactory::new()->state(['price' => 999]);

$widget = $premium
    ->state(fn (array $attributes): array => ['name' => $attributes['name'] . ' Pro'])
    ->make(['sku' => 'FIXED-SKU']); // make() override > states > definition
```

## Wiring the model: `HasIonsFactory`

```php
use Illuminate\Database\Eloquent\Model;
use Ions\Database\HasIonsFactory;

class Widget extends Model
{
    use HasIonsFactory;
}

Widget::factory()->count(3)->create();
```

`Model::factory()` resolves the factory class by a simple rule:

1. A `protected static string $factory = SomeFactory::class;` property on the
   model wins, when present.
2. Otherwise `{ModelNamespace}\Factories\{Model}Factory` — e.g. `App\Widget`
   resolves `App\Factories\WidgetFactory`, and `App\Models\Widget` resolves
   `App\Models\Factories\WidgetFactory`.

This is intentionally simpler than Laravel's `Database\Factories`
cross-namespace mapping: the factory lives in a `Factories` sub-namespace next
to the model (or wherever `$factory` points), nothing else. A missing factory
throws a `RuntimeException` naming the class that was expected.

## Faker

`$this->faker` inside a factory is a lazily-created, per-factory-instance
memoized `Faker\Generator`:

```php
protected function definition(): array
{
    return [
        'name'  => fn (): string => $this->faker->name(),
        'email' => fn (): string => $this->faker->unique()->safeEmail(),
    ];
}
```

**Dependency decision:** `fakerphp/faker` is a **require-dev** dependency of
the framework (mirroring Laravel, which also keeps faker out of production
installs). Host apps that want `$this->faker` must add it themselves:

```bash
composer require --dev fakerphp/faker
```

Accessing `$this->faker` without it installed throws a `RuntimeException` with
that hint. Factories that avoid faker (e.g. using `Str::random()`) work with
no extra dependency. Note that faker seeding (`$this->faker->seed(42)`) is
global (`mt_srand`), so seed immediately before building when you need
deterministic values.

## Seeding

Factories work anywhere the database is booted, including seeders:

```php
class WidgetSeeder
{
    public function run(): void
    {
        Widget::factory()->count(50)->create();
        Widget::factory()->state(['featured' => true])->count(5)->create();
    }
}
```

## Scope

Kept out deliberately (build these explicitly instead):

- **No `sequence()`** — use a callable state with your own counter, or loop.
- **No relationship helpers** (`has()`/`for()`/`afterCreating()`) — create
  related models explicitly:
  `Post::factory()->create(['user_id' => User::factory()->create()->id]);`
