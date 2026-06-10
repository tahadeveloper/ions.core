<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\Mail\Mailable;
use Ions\Mail\SendMailableJob;
use Ions\Support\Mail;
use Ions\Support\Queue;
use IonsFixture\VipWelcomeMailable;
use IonsFixture\WelcomeMailable;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

beforeEach(fn () => bootFixtureKernel());

/**
 * Bind a recording NON-fake mailer as the container 'mailer' — stands in for
 * the real Symfony mailer so tests can observe exactly what a real transport
 * would receive (MailFake gets special treatment in Mailable::send()).
 *
 * @return MailerInterface&object{received: list<RawMessage>}
 */
function bindStubRealMailer(): MailerInterface
{
    $stub = new class () implements MailerInterface {
        /** @var list<RawMessage> */
        public array $received = [];

        public function send(RawMessage $message, ?Envelope $envelope = null): void
        {
            $this->received[] = $message;
        }
    };

    Kernel::app()->instance('mailer', $stub);

    return $stub;
}

test('Mail::fake() records a sent mailable and assertSent(FQCN) matches it', function () {
    $fake = Mail::fake();

    (new WelcomeMailable('ion@example.test', 'Ion'))->send();

    Mail::assertSent(WelcomeMailable::class);
    Mail::assertSentCount(1);

    // The callable filter receives the materialized Symfony Email.
    Mail::assertSent(
        fn (Email $email) => $email->getSubject() === 'Welcome aboard'
            && $email->getTo()[0]->getAddress() === 'ion@example.test'
            && $email->getHtmlBody() === '<p>Welcome, Ion</p>'
    );

    expect($fake->sent()[0])->toBeInstanceOf(Email::class);
});

test('assertSent(FQCN) fails when no mail from that Mailable was sent', function () {
    Mail::fake();

    Mail::send(
        (new Email())
            ->from('from@example.test')
            ->to('to@example.test')
            ->subject('Plain')
            ->text('not a mailable')
    );

    Mail::assertSent(Email::class); // raw class-string matching is unchanged

    expect(fn () => Mail::assertSent(WelcomeMailable::class))
        ->toThrow(AssertionFailedError::class);
});

test('Mail::send() accepts a Mailable while the RawMessage path stays unchanged', function () {
    Mail::fake();

    Mail::send(new WelcomeMailable('ion@example.test', 'Ion'));
    Mail::send(
        (new Email())
            ->from('from@example.test')
            ->to('to@example.test')
            ->subject('Raw')
            ->text('raw path')
    );

    Mail::assertSentCount(2);
    Mail::assertSent(WelcomeMailable::class);
    Mail::assertSent(fn (Email $email) => $email->getSubject() === 'Raw');
});

test('mailable->queue() dispatches a SendMailableJob with connection and queue targeting', function () {
    Queue::fake();

    (new WelcomeMailable('ion@example.test', 'Ion'))->queue('database', 'emails');

    Queue::assertDispatched(
        SendMailableJob::class,
        fn (SendMailableJob $job) => $job->mailable instanceof WelcomeMailable
            && $job->connection === 'database'
            && $job->queue === 'emails'
    );
});

test('Mail::queue() is a passthrough to mailable->queue()', function () {
    Queue::fake();

    Mail::queue(new WelcomeMailable('ion@example.test', 'Ion'), 'database', 'emails');

    Queue::assertDispatched(
        SendMailableJob::class,
        fn (SendMailableJob $job) => $job->connection === 'database' && $job->queue === 'emails'
    );
});

test('a sync-queued mailable is actually sent through the mail fake (handle() path)', function () {
    Mail::fake();

    // No Queue::fake(): the default sync connection runs SendMailableJob::handle()
    // immediately, which renders + sends through the container 'mailer'.
    (new WelcomeMailable('ion@example.test', 'Ion'))->queue();

    Mail::assertSent(WelcomeMailable::class);
    Mail::assertSentCount(1);
});

test('a real (non-fake) mailer never receives the X-Ions-Mailable header on send()', function () {
    $stub = bindStubRealMailer();

    (new WelcomeMailable('ion@example.test', 'Ion'))->send();

    expect($stub->received)->toHaveCount(1)
        ->and($stub->received[0])->toBeInstanceOf(Email::class)
        ->and($stub->received[0]->getHeaders()->has(Mailable::CLASS_HEADER))->toBeFalse()
        // The rest of the message is untouched by the stripping.
        ->and($stub->received[0]->getSubject())->toBe('Welcome aboard')
        ->and($stub->received[0]->getHtmlBody())->toBe('<p>Welcome, Ion</p>');
});

test('a real (non-fake) mailer never receives the header via queue()+sync (worker path)', function () {
    $stub = bindStubRealMailer();

    // No Queue::fake(): the sync driver runs SendMailableJob::handle()
    // immediately — the worker send path must strip the header too.
    (new WelcomeMailable('ion@example.test', 'Ion'))->queue();

    expect($stub->received)->toHaveCount(1)
        ->and($stub->received[0]->getHeaders()->has(Mailable::CLASS_HEADER))->toBeFalse();
});

test('the MailFake keeps the X-Ions-Mailable header on send() and queue()+sync', function () {
    $fake = Mail::fake();

    (new WelcomeMailable('ion@example.test', 'Ion'))->send();
    (new WelcomeMailable('ion@example.test', 'Ion'))->queue();

    Mail::assertSentCount(2);
    Mail::assertSent(WelcomeMailable::class);

    foreach ($fake->sent() as $email) {
        expect($email->getHeaders()->getHeaderBody(Mailable::CLASS_HEADER))->toBe(WelcomeMailable::class);
    }
});

test('assertSent with a parent Mailable FQCN matches a subclass send (inheritance-aware)', function () {
    Mail::fake();

    (new VipWelcomeMailable('ion@example.test', 'Ion'))->send();

    Mail::assertSent(VipWelcomeMailable::class); // exact class
    Mail::assertSent(WelcomeMailable::class);    // parent class, via is_a()
    Mail::assertSent(Mailable::class);           // abstract base, same rule
});

test('a parent Mailable send does not match a subclass FQCN assertion', function () {
    Mail::fake();

    (new WelcomeMailable('ion@example.test', 'Ion'))->send();

    expect(fn () => Mail::assertSent(VipWelcomeMailable::class))
        ->toThrow(AssertionFailedError::class);
});

test('Mail::send() rejects an Envelope passed alongside a Mailable', function () {
    Mail::fake();

    $envelope = new Envelope(new Address('bounce@example.test'), [new Address('rcpt@example.test')]);

    expect(fn () => Mail::send(new WelcomeMailable('ion@example.test', 'Ion'), $envelope))
        ->toThrow(InvalidArgumentException::class, 'Envelope');

    Mail::assertNothingSent();
});

test('a SendMailableJob survives serialize/unserialize and still sends (database-driver realism)', function () {
    Mail::fake();

    $job = new SendMailableJob(new WelcomeMailable('ion@example.test', 'Ion'));

    // The database driver stores serialize($job) in the payload and the worker
    // unserializes it — prove the Mailable round-trips and renders in handle().
    $restored = unserialize(serialize($job));
    expect($restored)->toBeInstanceOf(SendMailableJob::class);

    $restored->handle();

    Mail::assertSent(WelcomeMailable::class);
    Mail::assertSent(fn (Email $email) => $email->getHtmlBody() === '<p>Welcome, Ion</p>');
});
