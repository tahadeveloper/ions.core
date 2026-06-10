<?php

declare(strict_types=1);

namespace IonsFixture\Notifications;

use Ions\Mail\Mailable;
use Ions\Notifications\Notification;

/**
 * Fan-out fixture: one via() targeting BOTH built-in channels.
 */
final class OrderShippedNotification extends Notification
{
    public function __construct(private readonly int $orderId)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): Mailable
    {
        return new GreetingMailable();
    }

    public function toDatabase(object $notifiable): array
    {
        return ['order_id' => $this->orderId];
    }
}
