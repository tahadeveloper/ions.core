<?php

declare(strict_types=1);

namespace Ions\Notifications;

use Ions\Mail\Mailable;
use LogicException;

/**
 * Base class for host-app notifications.
 *
 * A notification describes ONE event told to ONE notifiable (usually a user)
 * over one or more channels. Subclasses take their data through the
 * constructor, declare the target channels in via(), and implement a
 * to{Channel}() payload method per requested channel:
 *
 *   final class OrderShipped extends Notification {
 *       public function __construct(private int $orderId) {}
 *       public function via(object $notifiable): array { return ['mail', 'database']; }
 *       public function toMail(object $notifiable): Mailable { return new OrderShippedMail($this->orderId); }
 *       public function toDatabase(object $notifiable): array { return ['order_id' => $this->orderId]; }
 *   }
 *
 *   notify($user, new OrderShipped($order->id));
 *
 * The default toMail()/toDatabase() implementations throw a LogicException,
 * so requesting a channel in via() without implementing its payload method
 * fails loudly instead of silently delivering nothing.
 *
 * Deferred delivery: notify() is synchronous by design. A mail notification
 * defers by having the HOST queue the underlying Mailable
 * ({@see \Ions\Mail\Mailable::queue()}) or by wrapping notify() in a host
 * {@see \Ions\Queue\Job} — see docs/notifications.md.
 */
abstract class Notification
{
    /**
     * The channels this notification is delivered on for the given
     * notifiable. Built-ins: 'mail' and 'database'; hosts can map custom
     * names via config('notifications.channels').
     *
     * @return list<string>
     */
    abstract public function via(object $notifiable): array;

    /**
     * The Mailable to deliver when via() contains 'mail'. The mail channel
     * routes a recipient-less Mailable from the notifiable
     * ({@see Channels\MailChannel} for the resolution chain).
     */
    public function toMail(object $notifiable): Mailable
    {
        throw new LogicException(sprintf(
            "Notification [%s] requests the 'mail' channel but does not implement toMail().",
            static::class
        ));
    }

    /**
     * The serializable payload to persist when via() contains 'database'
     * (stored json-encoded in the notifications table's `data` column).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        throw new LogicException(sprintf(
            "Notification [%s] requests the 'database' channel but does not implement toDatabase().",
            static::class
        ));
    }
}
