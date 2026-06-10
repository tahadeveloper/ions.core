<?php

declare(strict_types=1);

namespace Ions\Support;

use Ions\Foundation\Kernel;
use Ions\Notifications\Contracts\Dispatcher;
use Ions\Notifications\Notification;
use Ions\Support\Concerns\ResolvesFake;
use Ions\Testing\Fakes\NotificationFake;

/**
 * Thin static facade over the container-bound notification dispatcher
 * ('notifications', bound lazily by
 * {@see \Ions\Providers\NotificationProvider}).
 *
 * In tests, {@see self::fake()} swaps the binding for a recording
 * {@see NotificationFake}; notify() and Notifications::send() both resolve
 * the binding, so every send is recorded and no channel runs. The container
 * is rebuilt per test boot, so an installed fake never leaks into the next
 * test.
 */
final class Notifications
{
    use ResolvesFake;

    /**
     * The container-bound dispatcher (the fake, once installed).
     */
    public static function dispatcher(): Dispatcher
    {
        /** @var Dispatcher $dispatcher */
        $dispatcher = Kernel::app()->get('notifications');

        return $dispatcher;
    }

    /**
     * Send $notification to $notifiable on every channel its via() names
     * (same as the notify() helper).
     */
    public static function send(object $notifiable, Notification $notification): void
    {
        self::dispatcher()->send($notifiable, $notification);
    }

    /**
     * Swap the 'notifications' binding for a recording fake and return it.
     */
    public static function fake(): NotificationFake
    {
        return self::installFake('notifications', new NotificationFake());
    }

    /**
     * @param class-string<Notification>                 $class
     * @param callable(Notification, object): bool|null $filter
     *
     * @see NotificationFake::assertSentTo()
     */
    public static function assertSentTo(object $notifiable, string $class, ?callable $filter = null): void
    {
        self::installedFake()->assertSentTo($notifiable, $class, $filter);
    }

    /**
     * @param class-string<Notification> $class
     *
     * @see NotificationFake::assertSentToTimes()
     */
    public static function assertSentToTimes(object $notifiable, string $class, int $times): void
    {
        self::installedFake()->assertSentToTimes($notifiable, $class, $times);
    }

    /**
     * @see NotificationFake::assertNothingSent()
     */
    public static function assertNothingSent(): void
    {
        self::installedFake()->assertNothingSent();
    }

    /**
     * The fake currently bound as 'notifications', or a hard failure pointing
     * at the missing Notifications::fake() call (without building the real
     * sender).
     */
    private static function installedFake(): NotificationFake
    {
        return self::resolveInstalledFake('notifications', NotificationFake::class, 'Notifications');
    }
}
