<?php

declare(strict_types=1);

namespace IonsFixture\Notifications;

use Ions\Mail\Mailable;

/**
 * Named Mailable fixture for the notification tests — declares NO recipient
 * in build(), so the mail channel must route it from the notifiable. Named
 * (not anonymous) so Mail::assertSent(GreetingMailable::class) FQCN matching
 * can be asserted end-to-end through notify().
 */
final class GreetingMailable extends Mailable
{
    public function build(): void
    {
        // Intentionally no to()/cc()/bcc(): the notifications MailChannel
        // resolves the recipient from the notifiable.
        $this->from('noreply@example.test', 'Ions Fixture')
            ->subject('Greetings')
            ->html('<p>Hello from a notification</p>');
    }
}
