<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\CommentAddedMail;
use Ions\Mail\Mailable;
use Ions\Notifications\Notification;

/**
 * "Someone commented on your task" — delivered on the mail + database channels.
 * Mirrors {@see TaskAssigned}: a recipient-less mailable routed by the mail
 * channel, plus a database payload for the in-app notification list.
 */
final class CommentAdded extends Notification
{
    public function __construct(
        private readonly int $taskId,
        private readonly string $taskTitle,
        private readonly string $actor,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): Mailable
    {
        return new CommentAddedMail($this->taskId, $this->taskTitle, $this->actor);
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'comment_added',
            'task_id' => $this->taskId,
            'actor' => $this->actor,
            'message' => sprintf('%s commented on "%s".', $this->actor, $this->taskTitle),
        ];
    }
}
