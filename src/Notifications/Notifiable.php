<?php

declare(strict_types=1);

namespace Ions\Notifications;

/**
 * Optional marker for notifiables that control their own mail routing.
 *
 * The {@see Channels\MailChannel} resolution is duck-typed — any object with
 * a routeNotificationForMail() method participates, interface or not — but
 * implementing this interface documents the contract and type-checks the
 * return shape.
 */
interface Notifiable
{
    /**
     * The mail recipient(s) for notifications routed to this object: a single
     * address, a list of addresses, or an address => display-name map (the
     * same shapes {@see \Ions\Mail\Mailable} to() accepts).
     *
     * @return string|array<int|string, string>
     */
    public function routeNotificationForMail(): string|array;
}
