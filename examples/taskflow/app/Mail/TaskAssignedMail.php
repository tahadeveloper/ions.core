<?php

declare(strict_types=1);

namespace App\Mail;

use Ions\Mail\Mailable;

/**
 * The mail body for the {@see \App\Notifications\TaskAssigned} notification.
 * Deliberately RECIPIENT-LESS: build() declares no to(), so the notifications
 * mail channel routes it to the notifiable's address (the assignee's email).
 */
final class TaskAssignedMail extends Mailable
{
    public function __construct(
        private readonly int $taskId,
        private readonly string $taskTitle,
    ) {
    }

    public function build(): void
    {
        $this->subject('A task was assigned to you')
            ->view('emails/task-assigned.twig', [
                'task_id' => $this->taskId,
                'task_title' => $this->taskTitle,
                'app_name' => config('app.name', 'Taskflow'),
            ]);
    }
}
