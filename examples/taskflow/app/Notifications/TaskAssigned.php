<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\TaskAssignedMail;
use Ions\Mail\Mailable;
use Ions\Notifications\Notification;

/**
 * "A task was assigned to you" — delivered on the mail + database channels.
 *
 * toMail() returns a recipient-less {@see TaskAssignedMail}: the mail channel
 * routes it to the notifiable's ->email (App\Models\User). toDatabase() returns
 * the payload persisted as a row in the notifications table (read back by the
 * in-app notification list, App\Http\Controllers\NotificationController).
 */
final class TaskAssigned extends Notification
{
    public function __construct(
        private readonly int $taskId,
        private readonly string $taskTitle,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): Mailable
    {
        return new TaskAssignedMail($this->taskId, $this->taskTitle);
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'task_assigned',
            'task_id' => $this->taskId,
            'message' => sprintf('You were assigned the task "%s".', $this->taskTitle),
        ];
    }
}
