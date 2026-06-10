<?php

declare(strict_types=1);

namespace IonsFixture\Notifications;

use Ions\Notifications\Contracts\Channel;
use Ions\Notifications\Notification;

/**
 * Custom channel fixture for the config('notifications.channels') merge
 * tests: records every delivery into a static list.
 */
final class RecordingChannel implements Channel
{
    /** @var list<array{notifiable: object, notification: Notification}> */
    public static array $sent = [];

    public function send(object $notifiable, Notification $notification): void
    {
        self::$sent[] = ['notifiable' => $notifiable, 'notification' => $notification];
    }
}
