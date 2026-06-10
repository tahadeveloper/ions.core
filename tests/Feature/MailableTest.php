<?php

declare(strict_types=1);

use Ions\Mail\SendMailableJob;
use Ions\Support\Mail;
use Ions\Support\Queue;
use IonsFixture\WelcomeMailable;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Mime\Email;

beforeEach(fn () => bootFixtureKernel());

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
