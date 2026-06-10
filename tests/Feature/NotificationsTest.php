<?php

declare(strict_types=1);

use Ions\Auth\Contracts\Authenticatable;
use Ions\Foundation\Kernel;
use Ions\Notifications\Contracts\Dispatcher;
use Ions\Notifications\NotificationSender;
use Ions\Support\Mail;
use Ions\Support\Notifications;
use Ions\Support\Request;
use Ions\Testing\Fakes\NotificationFake;
use IonsFixture\Notifications\GreetingMailable;
use IonsFixture\Notifications\OrderShippedNotification;
use IonsFixture\Notifications\ProfileUpdatedNotification;
use IonsFixture\Notifications\WelcomeNotification;
use PHPUnit\Framework\AssertionFailedError;

/*
|--------------------------------------------------------------------------
| Notifications — provider, notify() helper, fake (feature)
|--------------------------------------------------------------------------
*/

beforeEach(fn () => bootFixtureKernel());

/**
 * An Authenticatable notifiable with an ->email, usable by BOTH built-in
 * channels (the mail channel routes ->email; the database channel stores
 * getAuthIdentifier()).
 */
function featureNotifiable(string $id = 'user-7', string $email = 'user7@example.test'): Authenticatable
{
    return new class ($id, $email) implements Authenticatable {
        public function __construct(private readonly string $id, public string $email)
        {
        }

        public function getAuthIdentifier(): string|int
        {
            return $this->id;
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }
    };
}

test("NotificationProvider binds 'notifications' as a lazy singleton", function () {
    $app = Kernel::app();

    expect($app->bound('notifications'))->toBeTrue()
        ->and($app->resolved('notifications'))->toBeFalse();

    $sender = $app->get('notifications');

    expect($sender)->toBeInstanceOf(NotificationSender::class)
        ->and($sender)->toBeInstanceOf(Dispatcher::class)
        ->and($app->get('notifications'))->toBe($sender);
});

test("a normal request through the kernel never resolves 'notifications' (zero hot-path cost)", function () {
    $response = Kernel::handle(Request::create('/ping'));

    expect($response->getStatusCode())->toBe(200)
        ->and(Kernel::app()->resolved('notifications'))->toBeFalse();
});

test('notify() sends through the mail channel end-to-end: Mail::fake() matches the Mailable FQCN', function () {
    Mail::fake();

    notify(featureNotifiable(), new WelcomeNotification());

    Mail::assertSent(GreetingMailable::class);
    Mail::assertSentCount(1);
});

test('notify() persists a database notification row end-to-end', function () {
    createNotificationsTable();

    notify(featureNotifiable('user-9'), new ProfileUpdatedNotification(['name']));

    $rows = Kernel::app()->get('db')->getConnection()->table('notifications')->get()->all();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->notifiable_id)->toBe('user-9')
        ->and($rows[0]->type)->toBe(ProfileUpdatedNotification::class)
        ->and(json_decode((string) $rows[0]->data, true))->toBe(['changes' => ['name']]);
});

test('one via() can fan out to both built-in channels', function () {
    Mail::fake();
    createNotificationsTable();

    notify(featureNotifiable(), new OrderShippedNotification(1001));

    Mail::assertSent(GreetingMailable::class);

    $rows = Kernel::app()->get('db')->getConnection()->table('notifications')->get()->all();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->type)->toBe(OrderShippedNotification::class)
        ->and(json_decode((string) $rows[0]->data, true))->toBe(['order_id' => 1001]);
});

test("Notifications::fake() swaps the 'notifications' binding and records sends", function () {
    $fake = Notifications::fake();

    expect($fake)->toBeInstanceOf(NotificationFake::class)
        ->and(Kernel::app()->get('notifications'))->toBe($fake);

    $user = featureNotifiable();
    notify($user, new WelcomeNotification());

    expect($fake->sent())->toHaveCount(1)
        ->and($fake->sent()[0]['notifiable'])->toBe($user)
        ->and($fake->sent()[0]['notification'])->toBeInstanceOf(WelcomeNotification::class);
});

test('notify() routes through the fake, so real channels never run', function () {
    Notifications::fake();

    // WelcomeNotification targets the mail channel; with the fake installed
    // nothing must touch the mailer (which would otherwise build an SMTP
    // transport or need Mail::fake()).
    notify(featureNotifiable(), new WelcomeNotification());

    expect(Kernel::app()->resolved('mailer'))->toBeFalse();
    Notifications::assertSentTo(featureNotifiable(), WelcomeNotification::class);
});

test('assertSentTo passes for a matching class and fails for an unsent one', function () {
    Notifications::fake();
    $user = featureNotifiable();

    notify($user, new WelcomeNotification());

    Notifications::assertSentTo($user, WelcomeNotification::class);

    expect(fn () => Notifications::assertSentTo($user, ProfileUpdatedNotification::class))
        ->toThrow(AssertionFailedError::class);
});

test('assertSentTo matches the notifiable by identity or by class + auth identifier', function () {
    Notifications::fake();

    notify(featureNotifiable('user-7'), new WelcomeNotification());

    // A fresh instance with the SAME class and id matches...
    Notifications::assertSentTo(featureNotifiable('user-7'), WelcomeNotification::class);

    // ...a different id does not.
    expect(fn () => Notifications::assertSentTo(featureNotifiable('user-8'), WelcomeNotification::class))
        ->toThrow(AssertionFailedError::class);
});

test('assertSentTo accepts a filter over the notification (and the notifiable)', function () {
    Notifications::fake();
    $user = featureNotifiable();

    notify($user, new ProfileUpdatedNotification(['name']));

    Notifications::assertSentTo(
        $user,
        ProfileUpdatedNotification::class,
        fn (ProfileUpdatedNotification $n, object $notifiable): bool => $notifiable === $user
    );

    expect(fn () => Notifications::assertSentTo($user, ProfileUpdatedNotification::class, fn (): bool => false))
        ->toThrow(AssertionFailedError::class);
});

test('assertSentToTimes counts per notifiable and class', function () {
    Notifications::fake();
    $user = featureNotifiable();

    notify($user, new WelcomeNotification());
    notify($user, new WelcomeNotification());
    notify(featureNotifiable('someone-else'), new WelcomeNotification());

    Notifications::assertSentToTimes($user, WelcomeNotification::class, 2);

    expect(fn () => Notifications::assertSentToTimes($user, WelcomeNotification::class, 3))
        ->toThrow(AssertionFailedError::class);
});

test('assertNothingSent passes on silence and fails after a send', function () {
    Notifications::fake();

    Notifications::assertNothingSent();

    notify(featureNotifiable(), new WelcomeNotification());

    expect(fn () => Notifications::assertNothingSent())
        ->toThrow(AssertionFailedError::class);
});

test('assertions without Notifications::fake() fail pointing at the missing fake() call', function () {
    expect(fn () => Notifications::assertNothingSent())
        ->toThrow(RuntimeException::class, 'Notifications::fake()');

    // and the guard itself must not have resolved the real sender
    expect(Kernel::app()->resolved('notifications'))->toBeFalse();
});
