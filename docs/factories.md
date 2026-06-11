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

namespace Database\Factories;

use App\Models\Widget;
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

Generate one with `php bin/ions make:factory WidgetFactory`. The target follows
the host layout:

- **Host-root `database/` layout (since 4.4, recommended):** writes to
  `database/factories/` with the Laravel-standard `Database\Factories`
  namespace. This is the first-choice convention resolved by `HasIonsFactory`
  (see below). The host must map the namespace in its `composer.json` so the
  class autoloads:

  ```json
  "autoload": {
      "psr-4": {
          "App\\": "app/",
          "Database\\Factories\\": "database/factories/",
          "Database\\Seeders\\": "database/seeders/"
      }
  }
  ```

  Run `composer dump-autoload` after adding the mapping. `make:factory` prints
  this hint when the namespace is not yet autoloadable.
- **Legacy `{app|src}/Database` layout:** writes to `{app|src}/Factories/` with
  the `App\Factories` namespace. Here the model is inferred as `App\{Name}`
  (override with `--model=App\Models\Widget`); because the legacy factory lives
  in `App\Factories\` but the BC convention resolves
  `{ModelNamespace}\Factories\{Model}Factory`, for a model outside `App\` the
  command prints a note telling you to add
  `protected static string $factory = \App\Factories\WidgetFactory::class;` or
  move the factory.

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
`Illuminate\Database\Eloquent\Collection` otherwise. Note that the return type
keys off the runtime **value** of the count — `count($n)` with `$n === 1`
returns a bare model, not a 1-element collection — so callers with a dynamic
count should normalize the result (e.g. `Collection::wrap(...)`).

### States and overrides

`state()` layers overrides on top of the definition and is **immutable** —
each call returns a new factory, so partially-configured factories can be
shared safely. Array states merge over the current attributes; closure states
(`\Closure` only — a plain array is always treated as attributes, even one
that satisfies `is_callable()` like `['Foo', 'method']`) receive the current
attributes and return the overrides to merge. The attributes a closure state
receives are pre-evaluation: defaults declared as closures (like `sku` above)
are still raw `Closure` values at that point, because closure evaluation
happens only after all states and overrides are merged. States stack in
order, and attributes passed directly to `make()`/`create()` win over
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

`Model::factory()` resolves the factory class by trying these in order — the
first existing class wins:

1. A `protected static string $factory = SomeFactory::class;` property on the
   model, when present (explicit override).
2. **`Database\Factories\{Model}Factory`** — the Laravel-standard top-level
   namespace (since 4.4, the first-choice convention). E.g. `App\Models\Widget`
   resolves `Database\Factories\WidgetFactory`. This requires the host to map
   `"Database\\Factories\\": "database/factories/"` and
   `"Database\\Seeders\\": "database/seeders/"` in `composer.json` (see
   [Defining a factory](#defining-a-factory)).
3. `{ModelNamespace}\Factories\{Model}Factory` — the 4.2 convention, kept as a
   backwards-compatible fallback. E.g. `App\Widget` resolves
   `App\Factories\WidgetFactory`, and `App\Models\Widget` resolves
   `App\Models\Factories\WidgetFactory`.

Steps 2 and 3 are probed with `class_exists()`. A model needs **zero**
configuration when its factory follows convention 2 or 3. A missing factory
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

Factories work anywhere the database is booted, including seeders. Generate one
with `php bin/ions make:seeder WidgetSeeder` — on the host-root `database/`
layout it lands in `database/seeders/` with the `Database\Seeders` namespace
(the `"Database\\Seeders\\": "database/seeders/"` composer.json mapping,
alongside the factories mapping); on the legacy layout it lands in
`{app|src}/Database/Seeders/` with `App\Database\Seeders`.

```php
namespace Database\Seeders;

class WidgetSeeder
{
    public function seed(): void
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
