<?php

declare(strict_types=1);

namespace IonsFixture\Models\Factories;

use Ions\Database\Factory;
use Ions\Support\Str;
use IonsFixture\Models\Widget;

/**
 * Fixture factory resolved by convention from IonsFixture\Models\Widget
 * ({ModelNamespace}\Factories\{Model}Factory).
 *
 * @extends Factory<Widget>
 */
class WidgetFactory extends Factory
{
    protected string $model = Widget::class;

    protected function definition(): array
    {
        return [
            'name' => 'Widget',
            // Per-instance closure: receives the partially-built attributes
            // (earlier keys already resolved) and is re-evaluated per model.
            'sku' => fn (array $attributes): string => $attributes['name'] . '-' . Str::random(8),
            'price' => 100,
        ];
    }
}
