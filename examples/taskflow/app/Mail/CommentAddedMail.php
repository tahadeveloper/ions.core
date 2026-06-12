<?php

declare(strict_types=1);

namespace App\Mail;

use Ions\Mail\Mailable;

/**
 * The mail body for the {@see \App\Notifications\CommentAdded} notification.
 * Recipient-less, like {@see TaskAssignedMail}: routed to the notifiable by the
 * notifications mail channel.
 */
final class CommentAddedMail extends Mailable
{
    public function __construct(
        private readonly int $taskId,
        private readonly string $taskTitle,
        private readonly string $actor,
    ) {
    }

    public function build(): void
    {
        $this->subject('New comment on your task')
            ->view('emails/comment-added.twig', [
                'task_id' => $this->taskId,
                'task_title' => $this->taskTitle,
                'actor' => $this->actor,
                'app_name' => config('app.name', 'Taskflow'),
            ]);
    }
}
