<?php

declare(strict_types=1);

namespace Ions\Testing\Fakes;

use Ions\Mail\Mailable;
use PHPUnit\Framework\Assert;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Recording mailer fake for tests, installed via {@see \Ions\Support\Mail::fake()}.
 *
 * Implements Symfony's MailerInterface — the same surface the real 'mailer'
 * binding (Symfony Mailer) exposes — so anything that resolves the mailer
 * from the container (e.g. the newMailerDsn() helper) records messages here
 * instead of opening an SMTP connection. The Envelope (when the sender passed
 * one) is recorded alongside each message. Every assertion returns $this so
 * assertions chain.
 */
final class MailFake implements MailerInterface
{
    /** @var list<RawMessage> */
    private array $messages = [];

    /** @var list<Envelope|null> */
    private array $envelopes = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->messages[] = $message;
        $this->envelopes[] = $envelope;
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
     * The Symfony Envelope recorded for each send, index-aligned with
     * {@see sent()}. An entry is null when the sender did not pass an
     * explicit envelope (Symfony then computes one from the message).
     *
     * @return list<Envelope|null>
     */
    public function sentEnvelopes(): array
    {
        return $this->envelopes;
    }

    /**
     * Assert at least one message was sent. The optional filter narrows the
     * match: a class-string requires an instance of that message class — or,
     * for an {@see \Ions\Mail\Mailable} subclass FQCN, a message stamped with
     * that class in its X-Ions-Mailable header (Mailable::send() adds it) — a
     * callable receives each message plus its recorded envelope (null when
     * none was passed) and must return true for at least one.
     *
     * @param class-string|callable(RawMessage, Envelope|null): bool|null $filter
     */
    public function assertSent(string|callable|null $filter = null): static
    {
        if ($filter === null) {
            Assert::assertNotEmpty($this->messages, 'Expected at least one mail to be sent, but none were.');

            return $this;
        }

        if (is_string($filter)) {
            $class = $filter;
            $filter = static fn (RawMessage $message): bool => $message instanceof $class
                || self::mailableClass($message) === $class;
            $failure = sprintf('Expected a sent mail of class [%s], but none matched.', $class);
        } else {
            $failure = 'Expected at least one sent mail to match the given filter, but none did.';
        }

        $matched = false;
        foreach ($this->messages as $index => $message) {
            if ($filter($message, $this->envelopes[$index]) === true) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, $failure . sprintf(' (%d mail(s) recorded)', count($this->messages)));

        return $this;
    }

    /**
     * Assert exactly $count messages were sent.
     */
    public function assertSentCount(int $count): static
    {
        Assert::assertCount(
            $count,
            $this->messages,
            sprintf('Expected exactly %d sent mail(s), got %d.', $count, count($this->messages))
        );

        return $this;
    }

    /**
     * The Mailable FQCN a message was materialized from (the X-Ions-Mailable
     * header {@see Mailable::toSymfonyEmail()} stamps), or null for messages
     * not sent via a Mailable.
     */
    private static function mailableClass(RawMessage $message): ?string
    {
        if (!$message instanceof Message) {
            return null;
        }

        $header = $message->getHeaders()->get(Mailable::CLASS_HEADER);

        return $header?->getBodyAsString();
    }

    /**
     * Assert no messages were sent at all.
     */
    public function assertNothingSent(): static
    {
        Assert::assertCount(
            0,
            $this->messages,
            sprintf('Expected no mails to be sent, but %d were.', count($this->messages))
        );

        return $this;
    }
}
