<?php

declare(strict_types=1);

use Ions\Notifications\Channels\DatabaseChannel;
use Ions\Notifications\Channels\MailChannel;
use Ions\Notifications\Notification;
use Ions\Notifications\NotificationSender;
use IonsFixture\Notifications\RecordingChannel;

/*
|--------------------------------------------------------------------------
| NotificationSender — channel map resolution
|--------------------------------------------------------------------------
| Built-ins ('mail', 'database') merge with config('notifications.channels'),
| host entries winning on name collisions. Unknown names and non-Channel
| classes fail with a clear LogicException.
*/

beforeEach(function () {
    bootFixtureKernel();
    RecordingChannel::$sent = [];
});

function senderNotificationVia(array $channels): Notification
{
    return new class ($channels) extends Notification {
        /** @param list<string> $channels */
        public function __construct(private readonly array $channels)
        {
        }

        public function via(object $notifiable): array
        {
            return $this->channels;
        }
    };
}

test('a custom channel mapped via config(notifications.channels) receives the notification', function () {
    config(['notifications.channels' => ['pigeon' => RecordingChannel::class]]);

    $notifiable = new stdClass();
    $notification = senderNotificationVia(['pigeon']);

    (new NotificationSender())->send($notifiable, $notification);

    expect(RecordingChannel::$sent)->toHaveCount(1)
        ->and(RecordingChannel::$sent[0]['notifiable'])->toBe($notifiable)
        ->and(RecordingChannel::$sent[0]['notification'])->toBe($notification);
});

test('a host mapping can override a built-in channel name', function () {
    config(['notifications.channels' => ['mail' => RecordingChannel::class]]);

    // No toMail() implemented: the built-in MailChannel would throw, so a
    // recorded delivery proves the host class replaced it.
    (new NotificationSender())->send(new stdClass(), senderNotificationVia(['mail']));

    expect(RecordingChannel::$sent)->toHaveCount(1);
});

test('an unknown channel name throws a LogicException listing the known channels', function () {
    $sender = new NotificationSender();

    expect(fn () => $sender->send(new stdClass(), senderNotificationVia(['carrier-owl'])))
        ->toThrow(LogicException::class, 'carrier-owl');

    try {
        $sender->send(new stdClass(), senderNotificationVia(['carrier-owl']));
        $this->fail('Expected a LogicException.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toContain('mail')
            ->and($e->getMessage())->toContain('database')
            ->and($e->getMessage())->toContain('notifications.channels');
    }
});

test('a mapped class that does not implement Channel throws a LogicException', function () {
    config(['notifications.channels' => ['bogus' => stdClass::class]]);

    $sender = new NotificationSender();

    expect(fn () => $sender->send(new stdClass(), senderNotificationVia(['bogus'])))
        ->toThrow(LogicException::class, Ions\Notifications\Contracts\Channel::class);
});

test('via() returning an empty array sends nothing and throws nothing', function () {
    (new NotificationSender())->send(new stdClass(), senderNotificationVia([]));

    expect(RecordingChannel::$sent)->toBe([]);
});

test('the built-in channel classes exist and implement Channel', function () {
    expect(new MailChannel())->toBeInstanceOf(Ions\Notifications\Contracts\Channel::class)
        ->and(new DatabaseChannel())->toBeInstanceOf(Ions\Notifications\Contracts\Channel::class);
});
