<?php

declare(strict_types=1);

namespace Ions\Testing\Fakes;

use Ions\Auth\Contracts\Authenticatable;
use Ions\Notifications\Contracts\Dispatcher;
use Ions\Notifications\Notification;
use PHPUnit\Framework\Assert;

/**
 * Recording notification dispatcher for tests, installed via
 * {@see \Ions\Support\Notifications::fake()}. Implements the same
 * {@see Dispatcher} contract the real sender is bound under, so notify()
 * and Notifications::send() record here and NO channel runs (no mail, no
 * database row). Every assertion returns $this so assertions chain.
 *
 * Notifiable matching (assertSentTo/assertSentToTimes) accepts a different
 * instance than the one notified: two notifiables match when they are the
 * same object, or the same class AND the same identity — compared via
 * {@see Authenticatable::getAuthIdentifier()}, a duck-typed getKey()
 * (Eloquent/Sentinel models), or a readable ->id, in that order.
 */
final class NotificationFake implements Dispatcher
{
    /** @var list<array{notifiable: object, notification: Notification}> */
    private array $sent = [];

    public function send(object $notifiable, Notification $notification): void
    {
        $this->sent[] = ['notifiable' => $notifiable, 'notification' => $notification];
    }

    /**
     * All recorded sends, in order.
     *
     * @return list<array{notifiable: object, notification: Notification}>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    /**
     * Assert at least one $class notification (inheritance-aware, like
     * instanceof) was sent to $notifiable. The optional filter receives
     * (Notification, object $notifiable) per candidate and must return true
     * for at least one.
     *
     * @param class-string<Notification>                 $class
     * @param callable(Notification, object): bool|null $filter
     */
    public function assertSentTo(object $notifiable, string $class, ?callable $filter = null): static
    {
        $matched = false;
        foreach ($this->matching($notifiable, $class) as [$candidate, $notification]) {
            if ($filter === null || $filter($notification, $candidate) === true) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, sprintf(
            'Expected a [%s] notification sent to [%s], but none matched%s. (%d notification(s) recorded)',
            $class,
            $notifiable::class,
            $filter !== null ? ' the filter' : '',
            count($this->sent)
        ));

        return $this;
    }

    /**
     * Assert exactly $times $class notifications were sent to $notifiable.
     *
     * @param class-string<Notification> $class
     */
    public function assertSentToTimes(object $notifiable, string $class, int $times): static
    {
        $count = count($this->matching($notifiable, $class));

        Assert::assertSame($times, $count, sprintf(
            'Expected [%s] to be sent to [%s] exactly %d time(s), got %d.',
            $class,
            $notifiable::class,
            $times,
            $count
        ));

        return $this;
    }

    /**
     * Assert no notifications were sent at all.
     */
    public function assertNothingSent(): static
    {
        Assert::assertCount(0, $this->sent, sprintf(
            'Expected no notifications to be sent, but %d were.',
            count($this->sent)
        ));

        return $this;
    }

    /**
     * The recorded (notifiable, notification) pairs matching the given
     * notifiable and notification class.
     *
     * @param class-string<Notification> $class
     *
     * @return list<array{object, Notification}>
     */
    private function matching(object $notifiable, string $class): array
    {
        $matches = [];
        foreach ($this->sent as $entry) {
            if ($entry['notification'] instanceof $class && $this->isSameNotifiable($notifiable, $entry['notifiable'])) {
                $matches[] = [$entry['notifiable'], $entry['notification']];
            }
        }

        return $matches;
    }

    private function isSameNotifiable(object $expected, object $actual): bool
    {
        if ($expected === $actual) {
            return true;
        }

        if ($expected::class !== $actual::class) {
            return false;
        }

        if ($expected instanceof Authenticatable && $actual instanceof Authenticatable) {
            return (string) $expected->getAuthIdentifier() === (string) $actual->getAuthIdentifier();
        }

        if (method_exists($expected, 'getKey') && method_exists($actual, 'getKey')) {
            $expectedKey = $expected->getKey();
            $actualKey = $actual->getKey();

            // String-cast scalars so 7 matches '7' (manually-built vs
            // driver-hydrated models); two unsaved models (null keys) never match.
            if ($expectedKey === null || $actualKey === null) {
                return false;
            }

            if (is_scalar($expectedKey) && is_scalar($actualKey)) {
                return (string) $expectedKey === (string) $actualKey;
            }

            return $expectedKey === $actualKey;
        }

        if (isset($expected->id, $actual->id)) {
            return $expected->id === $actual->id;
        }

        return false;
    }
}
