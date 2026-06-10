<?php

declare(strict_types=1);

namespace Ions\Notifications;

use Ions\Foundation\Kernel;
use Ions\Notifications\Channels\DatabaseChannel;
use Ions\Notifications\Channels\MailChannel;
use Ions\Notifications\Contracts\Channel;
use Ions\Notifications\Contracts\Dispatcher;
use LogicException;

/**
 * The real 'notifications' dispatcher: resolves the notification's via()
 * list and delivers on each channel, synchronously and in order.
 *
 * Channel map = built-ins below merged with config('notifications.channels')
 * (name => class-string), host entries winning on name collisions. Channel
 * instances are built through the container — so hosts can constructor-inject
 * their own bindings into custom channels — validated against the
 * {@see Channel} contract, and cached per sender (the sender itself is a
 * lazy container singleton, {@see \Ions\Providers\NotificationProvider}).
 */
final class NotificationSender implements Dispatcher
{
    /** Built-in channel name => class map. */
    private const CHANNELS = [
        'mail' => MailChannel::class,
        'database' => DatabaseChannel::class,
    ];

    /** @var array<string, Channel> resolved channel instances */
    private array $resolved = [];

    public function send(object $notifiable, Notification $notification): void
    {
        foreach ($notification->via($notifiable) as $name) {
            $this->channel($name)->send($notifiable, $notification);
        }
    }

    /**
     * Resolve a channel name through the merged map.
     *
     * @throws LogicException on unknown names and non-Channel classes
     */
    private function channel(string $name): Channel
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        /** @var array<string, class-string> $custom */
        $custom = (array) config('notifications.channels', []);
        $map = array_merge(self::CHANNELS, $custom);

        $class = $map[$name] ?? throw new LogicException(sprintf(
            "Unknown notification channel '%s': known channels are [%s]. Map custom channels via config('notifications.channels').",
            $name,
            implode(', ', array_keys($map))
        ));

        $channel = Kernel::app()->make($class);

        if (!$channel instanceof Channel) {
            throw new LogicException(sprintf(
                "Notification channel '%s' maps to [%s], which does not implement %s.",
                $name,
                $class,
                Channel::class
            ));
        }

        return $this->resolved[$name] = $channel;
    }
}
