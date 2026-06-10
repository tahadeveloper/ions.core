<?php

declare(strict_types=1);

namespace IonsFixture\Models\Factories;

use Ions\Database\Factory;
use IonsFixture\Models\Widget;

/**
 * Fixture factory using the lazy $this->faker generator.
 *
 * @extends Factory<Widget>
 */
class FakerWidgetFactory extends Factory
{
    protected string $model = Widget::class;

    protected function definition(): array
    {
        return [
            'name' => fn (): string => $this->faker->name(),
            'sku' => fn (): string => $this->faker->uuid(),
            'price' => 100,
        ];
    }
}
