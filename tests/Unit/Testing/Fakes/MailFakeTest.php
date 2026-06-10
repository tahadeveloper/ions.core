<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\Support\Mail;
use Ions\Testing\Fakes\MailFake;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

beforeEach(fn () => bootFixtureKernel());

function buildTestEmail(string $subject = 'Hello'): Email
{
    return (new Email())
        ->from('from@example.test')
        ->to('to@example.test')
        ->subject($subject)
        ->text('body');
}

test('Mail::fake() swaps the container binding and returns the fake', function () {
    $fake = Mail::fake();

    expect($fake)->toBeInstanceOf(MailFake::class)
        ->and(Kernel::app()->get('mailer'))->toBe($fake);
});

test('the fake records Symfony emails sent through the mailer binding', function () {
    $fake = Mail::fake();

    Mail::send(buildTestEmail('Recorded'));

    $fake->assertSent();
    $fake->assertSentCount(1);
    expect($fake->sent())->toHaveCount(1)
        ->and($fake->sent()[0])->toBeInstanceOf(Email::class)
        ->and($fake->sent()[0]->getSubject())->toBe('Recorded');
});

test('the newMailerDsn() helper routes through the fake', function () {
    $envBackup = [$_ENV['MAIL_FROM_ADDRESS'] ?? null, $_ENV['MAIL_FROM_NAME'] ?? null];
    $_ENV['MAIL_FROM_ADDRESS'] = 'noreply@example.test';
    $_ENV['MAIL_FROM_NAME'] = 'Fixture';

    try {
        $fake = Mail::fake();

        expect(newMailerDsn('host@example.test', 'Helper subject', '<b>hi</b>'))->toBeTrue();

        $fake->assertSent(fn (Email $email) => $email->getSubject() === 'Helper subject');
        $fake->assertSentCount(1);
    } finally {
        [$_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']] = $envBackup;
    }
});

test('the fake records the envelope alongside each message', function () {
    $fake = Mail::fake();

    $envelope = new Envelope(
        new Address('bounce@example.test'),
        [new Address('rcpt@example.test')]
    );

    Mail::send(buildTestEmail('No envelope'));
    Mail::send(buildTestEmail('Custom envelope'), $envelope);

    expect($fake->sentEnvelopes())->toHaveCount(2)
        ->and($fake->sentEnvelopes()[0])->toBeNull()
        ->and($fake->sentEnvelopes()[1])->toBe($envelope)
        ->and($fake->sentEnvelopes()[1]->getSender()->getAddress())->toBe('bounce@example.test');
});

test('assertSent callables receive the recorded envelope as a second argument', function () {
    $fake = Mail::fake();

    $envelope = new Envelope(
        new Address('bounce@example.test'),
        [new Address('rcpt@example.test')]
    );

    Mail::send(buildTestEmail('With envelope'), $envelope);

    $fake->assertSent(
        fn (Email $email, ?Envelope $sentWith) => $sentWith === $envelope
            && $email->getSubject() === 'With envelope'
    );

    Mail::send(buildTestEmail('Bare'));

    $fake->assertSent(fn (Email $email, ?Envelope $sentWith) => $email->getSubject() === 'Bare' && $sentWith === null);
});

test('assertSent accepts a class-string filter', function () {
    $fake = Mail::fake();

    Mail::send(buildTestEmail());

    $fake->assertSent(Email::class);
});

test('assertSent accepts a filter callable receiving the message', function () {
    $fake = Mail::fake();

    Mail::send(buildTestEmail('Alpha'));

    $fake->assertSent(fn (Email $email) => $email->getSubject() === 'Alpha');

    expect(fn () => $fake->assertSent(fn (Email $email) => $email->getSubject() === 'Beta'))
        ->toThrow(AssertionFailedError::class);
});

test('assertSent fails when nothing was sent', function () {
    $fake = Mail::fake();

    expect(fn () => $fake->assertSent())
        ->toThrow(AssertionFailedError::class);
});

test('assertSentCount fails on the wrong count', function () {
    $fake = Mail::fake();

    Mail::send(buildTestEmail());

    $fake->assertSentCount(1);

    expect(fn () => $fake->assertSentCount(2))
        ->toThrow(AssertionFailedError::class);
});

test('assertNothingSent passes when quiet and fails after a send', function () {
    $fake = Mail::fake();

    $fake->assertNothingSent();

    Mail::send(buildTestEmail());

    expect(fn () => $fake->assertNothingSent())
        ->toThrow(AssertionFailedError::class);
});

test('assertions are reachable statically through the facade', function () {
    Mail::fake();

    Mail::send(buildTestEmail('Static'));

    Mail::assertSent(fn (Email $email) => $email->getSubject() === 'Static');
    Mail::assertSentCount(1);

    expect(fn () => Mail::assertNothingSent())
        ->toThrow(AssertionFailedError::class);
});

test('the Ions assertions chain by returning the fake', function () {
    $fake = Mail::fake();

    expect($fake->assertNothingSent())->toBe($fake);

    Mail::send(buildTestEmail('Chain'));

    expect($fake->assertSent())->toBe($fake)
        ->and($fake->assertSent(Email::class))->toBe($fake)
        ->and($fake->assertSentCount(1))->toBe($fake);
});

test('static assertions without an installed fake throw a helpful error', function () {
    expect(fn () => Mail::assertSent())
        ->toThrow(RuntimeException::class, 'Mail::fake()');
});
