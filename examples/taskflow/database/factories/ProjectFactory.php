<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Ions\Database\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected string $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    protected function definition(): array
    {
        return [
            // Create an owner by default; override owner_id to attach to an
            // existing user (the Factory base has no relationship helpers).
            'owner_id' => fn () => User::factory()->create()->getKey(),
            'name' => $this->faker->unique()->catchPhrase(),
            'note' => $this->faker->optional()->sentence(),
            'share_token' => null,
        ];
    }

    /** A project exposed via a public share token. */
    public function shared(): static
    {
        return $this->state(['share_token' => $this->faker->unique()->sha1()]);
    }
}
