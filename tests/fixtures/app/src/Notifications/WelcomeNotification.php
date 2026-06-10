<?php

declare(strict_types=1);

namespace IonsFixture\Notifications;

use Ions\Mail\Mailable;
use Ions\Notifications\Notification;

/**
 * Mail-only notification fixture: the end-to-end notify() tests assert the
 * GreetingMailable FQCN through Mail::fake().
 */
final class WelcomeNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): Mailable
    {
        return new GreetingMailable();
    }
}
