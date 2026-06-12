<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use Ions\Database\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    protected string $model = Comment::class;

    /**
     * @return array<string, mixed>
     */
    protected function definition(): array
    {
        return [
            'task_id' => fn () => Task::factory()->create()->getKey(),
            'author_id' => fn () => User::factory()->create()->getKey(),
            'body' => $this->faker->paragraph(),
        ];
    }
}
