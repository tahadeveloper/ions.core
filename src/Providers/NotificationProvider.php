<?php

declare(strict_types=1);

namespace Ions\Providers;

use Ions\Container\ServiceProvider;
use Ions\Notifications\NotificationSender;

/**
 * Binds the {@see NotificationSender} as the lazy 'notifications' singleton.
 *
 * Zero hot-path cost: registration stores only the closure; the sender (and
 * anything its channels touch — mailer, db) is built on the first
 * notify()/Notifications::send() call, never on a normal request.
 */
final class NotificationProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->container->bound('notifications')) {
            return;
        }

        $this->container->singleton('notifications', static fn (): NotificationSender => new NotificationSender());
    }
}
