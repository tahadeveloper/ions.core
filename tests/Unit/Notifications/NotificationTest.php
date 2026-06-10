<?php

declare(strict_types=1);

use Ions\Notifications\Notification;

/*
|--------------------------------------------------------------------------
| Notification base class — default channel-payload behavior
|--------------------------------------------------------------------------
| via() is abstract; toMail()/toDatabase() ship default implementations that
| throw a clear LogicException, so requesting a channel without implementing
| its payload method fails loudly with the notification class and the missing
| method in the message.
*/

function notificationWithoutPayloads(): Notification
{
    return new class () extends Notification {
        public function via(object $notifiable): array
        {
            return ['mail', 'database'];
        }
    };
}

test('toMail() defaults to a LogicException naming the mail channel and the missing override', function () {
    $notification = notificationWithoutPayloads();

    expect(fn () => $notification->toMail(new stdClass()))
        ->toThrow(LogicException::class, "requests the 'mail' channel but does not implement toMail()");
});

test('toDatabase() defaults to a LogicException naming the database channel and the missing override', function () {
    $notification = notificationWithoutPayloads();

    expect(fn () => $notification->toDatabase(new stdClass()))
        ->toThrow(LogicException::class, "requests the 'database' channel but does not implement toDatabase()");
});

test('the default-throw messages include the concrete notification class', function () {
    $notification = notificationWithoutPayloads();

    try {
        $notification->toMail(new stdClass());
        $this->fail('Expected a LogicException.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toContain($notification::class);
    }
});
