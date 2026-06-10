<?php

declare(strict_types=1);

namespace Ions\Notifications\Channels;

use Ions\Notifications\Contracts\Channel;
use Ions\Notifications\Notification;
use LogicException;

/**
 * The 'mail' channel: delivers the notification's toMail() Mailable through
 * the container 'mailer' (so Mail::fake() intercepts it like any other send).
 *
 * Recipient resolution — an explicit to()/cc()/bcc() declared in the
 * Mailable's build() always wins; only a recipient-less Mailable is routed
 * from the notifiable, walking this chain (each step falls through when it
 * yields nothing):
 *
 *   1. routeNotificationForMail() — duck-typed; the
 *      {@see \Ions\Notifications\Notifiable} interface documents the contract
 *      (string address, list of addresses, or address => name map).
 *   2. getEmail(): string getter.
 *   3. A readable ->email property/attribute (covers Eloquent and Sentinel
 *      users, whose attributes answer __isset/__get rather than being real
 *      properties).
 *
 * Nothing resolvable => LogicException (no half-built mail reaches the
 * mailer).
 */
final class MailChannel implements Channel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $mailable = $notification->toMail($notifiable);

        if (!$mailable->hasRecipients()) {
            $address = $this->resolveAddress($notifiable);

            if ($address === null) {
                throw new LogicException(sprintf(
                    'Notification [%s] cannot be mailed: the Mailable [%s] declares no recipient and the notifiable [%s] '
                    . 'resolves no address — give it a routeNotificationForMail() method, a getEmail() getter, '
                    . 'or an ->email property (or call to() in the Mailable\'s build()).',
                    $notification::class,
                    $mailable::class,
                    $notifiable::class
                ));
            }

            $mailable->routeTo($address);
        }

        $mailable->send();
    }

    /**
     * Walk the resolution chain documented on the class. Returns null when
     * no step yields a usable address.
     *
     * @return string|array<int|string, string>|null
     */
    private function resolveAddress(object $notifiable): string|array|null
    {
        if (method_exists($notifiable, 'routeNotificationForMail')) {
            $address = $notifiable->routeNotificationForMail();

            if ((is_string($address) && $address !== '') || (is_array($address) && $address !== [])) {
                return $address;
            }
        }

        if (method_exists($notifiable, 'getEmail')) {
            $address = $notifiable->getEmail();

            if (is_string($address) && $address !== '') {
                return $address;
            }
        }

        // isset() (not property_exists) so Eloquent/Sentinel virtual
        // attributes answering __isset/__get participate too.
        if (isset($notifiable->email)) {
            $address = $notifiable->email;

            if (is_string($address) && $address !== '') {
                return $address;
            }
        }

        return null;
    }
}
