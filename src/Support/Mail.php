<?php

declare(strict_types=1);

namespace Ions\Support;

use Ions\Foundation\Kernel;
use Ions\Support\Concerns\ResolvesFake;
use Ions\Testing\Fakes\MailFake;
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
    use ResolvesFake;

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
        return self::installFake('mailer', new MailFake());
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
     * missing Mail::fake() call (without lazily building the SMTP transport).
     */
    private static function installedFake(): MailFake
    {
        return self::resolveInstalledFake('mailer', MailFake::class, 'Mail');
    }
}
