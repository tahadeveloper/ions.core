<?php

declare(strict_types=1);

use Ions\Auth\Contracts\Authenticatable;
use Ions\Foundation\Kernel;
use Ions\Notifications\Channels\DatabaseChannel;
use Ions\Notifications\Notification;

/*
|--------------------------------------------------------------------------
| DatabaseChannel — insert shape + notifiable id resolution
|--------------------------------------------------------------------------
| Rows go into config('notifications.table', 'notifications') through the
| 'db' capsule: uuid id, morph pair, notification FQCN, json payload,
| nullable read_at, created_at. Notifiable id resolution chain:
| Authenticatable::getAuthIdentifier() → getKey() → ->id property.
*/

const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

beforeEach(function () {
    bootFixtureKernel();
    createNotificationsTable();
});

function databaseChannelNotification(array $payload): Notification
{
    return new class ($payload) extends Notification {
        /** @param array<string, mixed> $payload */
        public function __construct(private readonly array $payload)
        {
        }

        public function via(object $notifiable): array
        {
            return ['database'];
        }

        public function toDatabase(object $notifiable): array
        {
            return $this->payload;
        }
    };
}

function authenticatableNotifiable(string $id = 'user-7'): Authenticatable
{
    return new class ($id) implements Authenticatable {
        public function __construct(private readonly string $id)
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

function notificationRows(string $table = 'notifications'): array
{
    return Kernel::app()->get('db')->getConnection()->table($table)->get()->all();
}

test('inserts a row with a uuid id, the notification FQCN type, json data, and a null read_at', function () {
    $notifiable = authenticatableNotifiable();
    $notification = databaseChannelNotification(['changes' => ['name', 'email']]);

    (new DatabaseChannel())->send($notifiable, $notification);

    $rows = notificationRows();
    expect($rows)->toHaveCount(1);

    $row = $rows[0];
    expect((string) $row->id)->toMatch(UUID_PATTERN)
        ->and($row->notifiable_id)->toBe('user-7')
        ->and($row->notifiable_type)->toBe($notifiable::class)
        ->and($row->type)->toBe($notification::class)
        ->and(json_decode((string) $row->data, true))->toBe(['changes' => ['name', 'email']])
        ->and($row->read_at)->toBeNull()
        ->and($row->created_at)->not->toBeNull();
});

test('every insert gets its own uuid', function () {
    $channel = new DatabaseChannel();
    $channel->send(authenticatableNotifiable(), databaseChannelNotification(['n' => 1]));
    $channel->send(authenticatableNotifiable(), databaseChannelNotification(['n' => 2]));

    $ids = array_map(static fn (object $row): string => (string) $row->id, notificationRows());

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->not->toBe($ids[1]);
});

test('the table name is configurable via config(notifications.table)', function () {
    createNotificationsTable('custom_notes');
    config(['notifications.table' => 'custom_notes']);

    (new DatabaseChannel())->send(authenticatableNotifiable(), databaseChannelNotification(['x' => 1]));

    expect(notificationRows('custom_notes'))->toHaveCount(1)
        ->and(notificationRows())->toHaveCount(0);
});

test('a getKey() notifiable (Eloquent-style) resolves its id duck-typed', function () {
    $notifiable = new class () {
        public function getKey(): int
        {
            return 42;
        }
    };

    (new DatabaseChannel())->send($notifiable, databaseChannelNotification(['x' => 1]));

    expect((string) notificationRows()[0]->notifiable_id)->toBe('42');
});

test('a plain ->id property is the final id fallback', function () {
    $notifiable = new class () {
        public int $id = 7;
    };

    (new DatabaseChannel())->send($notifiable, databaseChannelNotification(['x' => 1]));

    expect((string) notificationRows()[0]->notifiable_id)->toBe('7');
});

test('a notifiable with no resolvable id throws a LogicException and inserts nothing', function () {
    $channel = new DatabaseChannel();
    $notification = databaseChannelNotification(['x' => 1]);

    expect(fn () => $channel->send(new stdClass(), $notification))
        ->toThrow(LogicException::class, 'getAuthIdentifier');

    expect(notificationRows())->toHaveCount(0);
});
