<?php

declare(strict_types=1);

use Ions\Mail\Mailable;
use Ions\Notifications\Channels\MailChannel;
use Ions\Notifications\Notifiable;
use Ions\Notifications\Notification;
use Ions\Support\Mail;
use IonsFixture\Notifications\GreetingMailable;
use IonsFixture\WelcomeMailable;
use Symfony\Component\Mime\Email;

/*
|--------------------------------------------------------------------------
| MailChannel — recipient routing chain
|--------------------------------------------------------------------------
| 1. An explicit to()/cc()/bcc() declared in the Mailable's build() wins.
| 2. Otherwise: routeNotificationForMail() → getEmail() → ->email property.
| 3. Nothing resolvable → LogicException naming the chain.
*/

beforeEach(function () {
    bootFixtureKernel();
    $this->mailer = Mail::fake();
});

function mailChannelNotification(Mailable $mailable): Notification
{
    return new class ($mailable) extends Notification {
        public function __construct(private readonly Mailable $mailable)
        {
        }

        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): Mailable
        {
            return $this->mailable;
        }
    };
}

function firstRecipientOf(\Ions\Testing\Fakes\MailFake $mailer): string
{
    $sent = $mailer->sent();
    expect($sent)->toHaveCount(1);

    /** @var Email $email */
    $email = $sent[0];

    return $email->getTo()[0]->getAddress();
}

test('an explicit to() in the Mailable build() wins over the notifiable route', function () {
    // WelcomeMailable declares to('explicit@...') in build().
    $notifiable = new class () implements Notifiable {
        public function routeNotificationForMail(): string|array
        {
            return 'routed@example.test';
        }
    };

    (new MailChannel())->send($notifiable, mailChannelNotification(new WelcomeMailable('explicit@example.test', 'Ion')));

    expect(firstRecipientOf($this->mailer))->toBe('explicit@example.test');
});

test('routeNotificationForMail() routes a recipient-less mailable', function () {
    $notifiable = new class () implements Notifiable {
        public function routeNotificationForMail(): string|array
        {
            return 'routed@example.test';
        }
    };

    (new MailChannel())->send($notifiable, mailChannelNotification(new GreetingMailable()));

    expect(firstRecipientOf($this->mailer))->toBe('routed@example.test');
});

test('routeNotificationForMail() works duck-typed, without the Notifiable interface', function () {
    $notifiable = new class () {
        public function routeNotificationForMail(): string
        {
            return 'duck@example.test';
        }
    };

    (new MailChannel())->send($notifiable, mailChannelNotification(new GreetingMailable()));

    expect(firstRecipientOf($this->mailer))->toBe('duck@example.test');
});

test('routeNotificationForMail() may return an address => name map', function () {
    $notifiable = new class () implements Notifiable {
        public function routeNotificationForMail(): string|array
        {
            return ['named@example.test' => 'Named Person'];
        }
    };

    (new MailChannel())->send($notifiable, mailChannelNotification(new GreetingMailable()));

    /** @var Email $email */
    $email = $this->mailer->sent()[0];

    expect($email->getTo()[0]->getAddress())->toBe('named@example.test')
        ->and($email->getTo()[0]->getName())->toBe('Named Person');
});

test('getEmail() is used when routeNotificationForMail() is absent', function () {
    $notifiable = new class () {
        public function getEmail(): string
        {
            return 'getter@example.test';
        }
    };

    (new MailChannel())->send($notifiable, mailChannelNotification(new GreetingMailable()));

    expect(firstRecipientOf($this->mailer))->toBe('getter@example.test');
});

test('the ->email property is the final fallback', function () {
    $notifiable = new class () {
        public string $email = 'property@example.test';
    };

    (new MailChannel())->send($notifiable, mailChannelNotification(new GreetingMailable()));

    expect(firstRecipientOf($this->mailer))->toBe('property@example.test');
});

test('routeNotificationForMail() takes precedence over getEmail() and ->email', function () {
    $notifiable = new class () implements Notifiable {
        public string $email = 'property@example.test';

        public function routeNotificationForMail(): string|array
        {
            return 'routed@example.test';
        }

        public function getEmail(): string
        {
            return 'getter@example.test';
        }
    };

    (new MailChannel())->send($notifiable, mailChannelNotification(new GreetingMailable()));

    expect(firstRecipientOf($this->mailer))->toBe('routed@example.test');
});

test('an unroutable notifiable throws a LogicException naming the resolution chain', function () {
    $channel = new MailChannel();
    $notification = mailChannelNotification(new GreetingMailable());

    expect(fn () => $channel->send(new stdClass(), $notification))
        ->toThrow(LogicException::class, 'routeNotificationForMail');

    try {
        $channel->send(new stdClass(), $notification);
        $this->fail('Expected a LogicException.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toContain('getEmail')
            ->and($e->getMessage())->toContain('email')
            ->and($e->getMessage())->toContain($notification::class);
    }

    $this->mailer->assertNothingSent();
});
