<?php

declare(strict_types=1);

namespace Ions\Notifications\Contracts;

use Ions\Notifications\Notification;

/**
 * A delivery channel for notifications. Built-ins:
 * {@see \Ions\Notifications\Channels\MailChannel} ('mail') and
 * {@see \Ions\Notifications\Channels\DatabaseChannel} ('database').
 *
 * Hosts add their own by mapping a name to an implementing class in
 * config('notifications.channels') — instances are built through the
 * container, so constructor dependencies the host has bound are injected.
 */
interface Channel
{
    /**
     * Deliver $notification to $notifiable on this channel.
     */
    public function send(object $notifiable, Notification $notification): void;
}
