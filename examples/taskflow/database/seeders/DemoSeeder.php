<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * A small demo dataset for exploring Taskflow with `ions serve`: two users, a
 * shared project with members, a handful of tasks (one assigned, one done) and
 * a comment. All demo users sign in with the password "password".
 */
class DemoSeeder
{
    public function seed(): void
    {
        $alice = User::factory()->create([
            'name' => 'Alice Owner',
            'email' => 'alice@example.com',
        ]);

        $bob = User::factory()->create([
            'name' => 'Bob Member',
            'email' => 'bob@example.com',
        ]);

        $project = Project::factory()->create([
            'owner_id' => $alice->getKey(),
            'name' => 'Taskflow Launch',
            'note' => 'Internal notes for the launch project.',
        ]);

        // Both users are members of the project (the owner included).
        $project->members()->sync([$alice->getKey(), $bob->getKey()]);

        Task::factory()->create([
            'project_id' => $project->getKey(),
            'assignee_id' => $bob->getKey(),
            'title' => 'Write the landing page',
            'status' => Task::STATUS_DOING,
        ]);

        $shipped = Task::factory()->done()->create([
            'project_id' => $project->getKey(),
            'assignee_id' => $alice->getKey(),
            'title' => 'Set up CI',
        ]);

        Task::factory()->todo()->create([
            'project_id' => $project->getKey(),
            'title' => 'Draft the README',
        ]);

        Comment::factory()->create([
            'task_id' => $shipped->getKey(),
            'author_id' => $alice->getKey(),
            'body' => 'CI is green on the example suite.',
        ]);
    }
}
