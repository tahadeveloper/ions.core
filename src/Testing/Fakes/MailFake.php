<?php

declare(strict_types=1);

namespace Ions\Testing\Fakes;

use PHPUnit\Framework\Assert;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Recording mailer fake for tests, installed via {@see \Ions\Support\Mail::fake()}.
 *
 * Implements Symfony's MailerInterface — the same surface the real 'mailer'
 * binding (Symfony Mailer) exposes — so anything that resolves the mailer
 * from the container (e.g. the newMailerDsn() helper) records messages here
 * instead of opening an SMTP connection.
 */
final class MailFake implements MailerInterface
{
    /** @var list<RawMessage> */
    private array $messages = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->messages[] = $message;
    }

    /**
     * All recorded messages, in send order. Hosts send Symfony
     * {@see \Symfony\Component\Mime\Email} objects, so entries can usually be
     * treated as such.
     *
     * @return list<RawMessage>
     */
    public function sent(): array
    {
        return $this->messages;
    }

    /**
     * Assert at least one message was sent. The optional filter narrows the
     * match: a class-string requires an instance of that message class, a
     * callable receives each message and must return true for at least one.
     *
     * @param class-string|callable(RawMessage): bool|null $filter
     */
    public function assertSent(string|callable|null $filter = null): void
    {
        if ($filter === null) {
            Assert::assertNotEmpty($this->messages, 'Expected at least one mail to be sent, but none were.');

            return;
        }

        if (is_string($filter)) {
            $class = $filter;
            $filter = static fn (RawMessage $message): bool => $message instanceof $class;
            $failure = sprintf('Expected a sent mail of class [%s], but none matched.', $class);
        } else {
            $failure = 'Expected at least one sent mail to match the given filter, but none did.';
        }

        $matched = false;
        foreach ($this->messages as $message) {
            if ($filter($message) === true) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, $failure . sprintf(' (%d mail(s) recorded)', count($this->messages)));
    }

    /**
     * Assert exactly $count messages were sent.
     */
    public function assertSentCount(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->messages,
            sprintf('Expected exactly %d sent mail(s), got %d.', $count, count($this->messages))
        );
    }

    /**
     * Assert no messages were sent at all.
     */
    public function assertNothingSent(): void
    {
        Assert::assertCount(
            0,
            $this->messages,
            sprintf('Expected no mails to be sent, but %d were.', count($this->messages))
        );
    }
}
