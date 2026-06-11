<?php

declare(strict_types=1);

namespace Database\Factories;

use Ions\Database\Factory;
use IonsFixture\Models\Gadget;

/**
 * Fixture factory in the Laravel-standard top-level `Database\Factories`
 * namespace. Proves the 11.2 first-choice resolution in HasIonsFactory:
 * IonsFixture\Models\Gadget has neither a $factory property nor a conventional
 * IonsFixture\Models\Factories\GadgetFactory, so Gadget::factory() must resolve
 * THIS class via the Database\Factories\{Model}Factory rule.
 *
 * @extends Factory<Gadget>
 */
class GadgetFactory extends Factory
{
    protected string $model = Gadget::class;

    /**
     * @return array<string, mixed>
     */
    protected function definition(): array
    {
        return [
            'name' => 'Gadget',
            'sku' => 'Gadget-001',
            'price' => 50,
        ];
    }
}
