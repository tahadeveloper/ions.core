<?php

declare(strict_types=1);

namespace App\Mail;

use Ions\Mail\Mailable;

/**
 * Welcome email sent to a freshly-registered user. Constructed with plain
 * scalar state (email + name) so it serializes cleanly when queued; build()
 * renders the views/emails/welcome.twig template at send time.
 *
 *     (new WelcomeMail($user->email, $user->name))->send();
 */
final class WelcomeMail extends Mailable
{
    public function __construct(
        private readonly string $email,
        private readonly string $name,
    ) {
    }

    public function build(): void
    {
        $this->to($this->email)
            ->subject('Welcome to Taskflow')
            ->view('emails/welcome.twig', [
                'name' => $this->name,
                'app_name' => config('app.name', 'Taskflow'),
            ]);
    }
}
