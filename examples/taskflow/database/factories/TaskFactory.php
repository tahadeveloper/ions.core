<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\Task;
use Ions\Database\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected string $model = Task::class;

    /**
     * @return array<string, mixed>
     */
    protected function definition(): array
    {
        return [
            // Create a project by default; override to attach to an existing one.
            'project_id' => fn () => Project::factory()->create()->getKey(),
            'assignee_id' => null,
            'title' => rtrim($this->faker->sentence(4), '.'),
            'description' => $this->faker->optional()->paragraph(),
            'status' => $this->faker->randomElement(Task::STATUSES),
        ];
    }

    /** A task in the todo column. */
    public function todo(): static
    {
        return $this->state(['status' => Task::STATUS_TODO]);
    }

    /** A task in progress. */
    public function doing(): static
    {
        return $this->state(['status' => Task::STATUS_DOING]);
    }

    /** A completed task. */
    public function done(): static
    {
        return $this->state(['status' => Task::STATUS_DONE]);
    }
}
