<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Task;
use Ions\Database\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected string $model = Attachment::class;

    /**
     * @return array<string, mixed>
     */
    protected function definition(): array
    {
        $name = $this->faker->word() . '.' . $this->faker->randomElement(['pdf', 'png', 'txt']);

        return [
            'task_id' => fn () => Task::factory()->create()->getKey(),
            'path' => 'uploads/' . $this->faker->uuid() . '/' . $name,
            'original_name' => $name,
            'size' => $this->faker->numberBetween(128, 5_000_000),
        ];
    }
}
