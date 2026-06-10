<?php

declare(strict_types=1);

namespace Ions\Support;

use Ions\Foundation\Kernel;
use Ions\Testing\Fakes\MailFake;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Thin static facade over the container-bound Symfony mailer ('mailer',
 * bound lazily by {@see \Ions\Providers\MailProvider}).
 *
 * In tests, {@see self::fake()} swaps the binding for a recording
 * {@see MailFake}; anything sending through the container's mailer (including
 * the newMailerDsn() helper) is then recorded instead of hitting SMTP, and
 * the assertion passthroughs below resolve the installed fake. The container
 * is rebuilt per test boot, so an installed fake never leaks into the next
 * test.
 */
final class Mail
{
    /**
     * The container-bound mailer (the fake, once installed).
     */
    public static function mailer(): MailerInterface
    {
        /** @var MailerInterface $mailer */
        $mailer = Kernel::app()->get('mailer');

        return $mailer;
    }

    /**
     * Send a Symfony message (usually a {@see \Symfony\Component\Mime\Email})
     * through the container-bound mailer.
     */
    public static function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        self::mailer()->send($message, $envelope);
    }

    /**
     * Swap the 'mailer' binding for a recording fake and return it.
     */
    public static function fake(): MailFake
    {
        $fake = new MailFake();

        Kernel::app()->instance('mailer', $fake);

        return $fake;
    }

    /**
     * @param class-string|callable(RawMessage, Envelope|null): bool|null $filter
     *
     * @see MailFake::assertSent()
     */
    public static function assertSent(string|callable|null $filter = null): void
    {
        self::installedFake()->assertSent($filter);
    }

    /**
     * @see MailFake::assertSentCount()
     */
    public static function assertSentCount(int $count): void
    {
        self::installedFake()->assertSentCount($count);
    }

    /**
     * @see MailFake::assertNothingSent()
     */
    public static function assertNothingSent(): void
    {
        self::installedFake()->assertNothingSent();
    }

    /**
     * The fake currently bound as 'mailer', or a hard failure pointing at the
     * missing Mail::fake() call.
     */
    private static function installedFake(): MailFake
    {
        $app = Kernel::app();

        // resolved() is checked first so a missing fake fails with the message
        // below instead of lazily building the real SMTP transport.
        $mailer = $app->resolved('mailer') ? $app->get('mailer') : null;

        if (!$mailer instanceof MailFake) {
            throw new RuntimeException(
                'Mail assertions require the fake: call Mail::fake() in your test before sending mail.'
            );
        }

        return $mailer;
    }
}
