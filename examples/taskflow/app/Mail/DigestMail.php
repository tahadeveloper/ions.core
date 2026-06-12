<?php

declare(strict_types=1);

namespace App\Mail;

use Ions\Mail\Mailable;

/**
 * Weekly activity digest mailed to an active user. Carries the recipient plus
 * a small open-task count (plain state) so it queues cleanly; the scheduler
 * (see App\Schedule) dispatches one per active user every week.
 *
 *     (new DigestMail($user->email, $user->name, $openTasks))->queue('database');
 */
final class DigestMail extends Mailable
{
    public function __construct(
        private readonly string $email,
        private readonly string $name,
        private readonly int $openTasks,
    ) {
    }

    public function build(): void
    {
        $this->to($this->email)
            ->subject('Your weekly Taskflow digest')
            ->view('emails/digest.twig', [
                'name' => $this->name,
                'open_tasks' => $this->openTasks,
                'app_name' => config('app.name', 'Taskflow'),
            ]);
    }
}
