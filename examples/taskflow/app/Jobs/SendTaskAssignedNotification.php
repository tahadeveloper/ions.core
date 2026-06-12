<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;
use Ions\Queue\Job;

/**
 * Dispatched when a task is (re)assigned to a user (see TaskController::assign
 * and TaskApiController::assign). On handle() it notifies the assignee with the
 * {@see TaskAssigned} notification, which fans out to the mail + database
 * channels.
 *
 * The job carries only ids (plain serializable state, per Ions\Queue\Job): it
 * re-reads the Task and assignee User in handle() so a queued run picks up the
 * current rows. A vanished task/assignee is a no-op — the assignment that
 * triggered this may have been undone before the worker ran.
 */
final class SendTaskAssignedNotification extends Job
{
    public function __construct(
        private readonly int $taskId,
        private readonly int $assigneeId,
    ) {
    }

    public function handle(): void
    {
        $task = Task::query()->find($this->taskId);
        $assignee = User::query()->find($this->assigneeId);

        if ($task === null || $assignee === null) {
            return;
        }

        notify($assignee, new TaskAssigned($task->getKey(), $task->title));
    }
}
