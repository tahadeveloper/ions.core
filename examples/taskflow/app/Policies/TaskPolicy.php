<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

/**
 * Authorization for tasks, delegated to the task's project: a user who may
 * view/update/delete the parent project may do the same to its tasks (delete
 * is owner-only, matching {@see ProjectPolicy}).
 */
final class TaskPolicy
{
    public function __construct(private readonly ProjectPolicy $projects)
    {
    }

    public function view(User $user, Task $task): bool
    {
        return $this->projects->view($user, $task->project);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->projects->update($user, $task->project);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->projects->delete($user, $task->project);
    }
}
