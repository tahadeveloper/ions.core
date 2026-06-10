<?php

declare(strict_types=1);

namespace IonsFixture\Notifications;

use Ions\Notifications\Notification;

/**
 * Database-only notification fixture carrying a plain payload.
 */
final class ProfileUpdatedNotification extends Notification
{
    /**
     * @param list<string> $changes
     */
    public function __construct(private readonly array $changes)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['changes' => $this->changes];
    }
}
