<?php

declare(strict_types=1);

namespace Ions\Notifications\Contracts;

use Ions\Notifications\Notification;

/**
 * The notification dispatch surface bound as 'notifications' in the
 * container. The real implementation is
 * {@see \Ions\Notifications\NotificationSender}; tests swap in the recording
 * {@see \Ions\Testing\Fakes\NotificationFake} via Notifications::fake().
 * The notify() helper and the {@see \Ions\Support\Notifications} facade both
 * resolve this binding, so the fake intercepts every send path.
 */
interface Dispatcher
{
    /**
     * Deliver $notification to $notifiable on every channel its via() names.
     */
    public function send(object $notifiable, Notification $notification): void;
}
