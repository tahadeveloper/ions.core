<?php

declare(strict_types=1);

namespace Ions\Notifications\Channels;

use Illuminate\Database\Capsule\Manager;
use Ions\Auth\Contracts\Authenticatable;
use Ions\Foundation\Kernel;
use Ions\Notifications\Contracts\Channel;
use Ions\Notifications\Notification;
use Ions\Support\Str;
use LogicException;
use RuntimeException;

/**
 * The 'database' channel: persists the notification's toDatabase() payload
 * as a row in config('notifications.table', 'notifications') through the
 * container 'db' capsule (default connection).
 *
 * Row shape (DDL ships at src/Notifications/stubs/create_notifications_table.stub):
 *   id              uuid (Str::uuid, generated per insert)
 *   notifiable_type FQCN of the notifiable
 *   notifiable_id   see the resolution chain below
 *   type            FQCN of the notification
 *   data            json_encode(toDatabase())
 *   read_at         null (hosts mark rows read by setting it)
 *   created_at      insert time
 *
 * Notifiable id resolution chain (first hit wins):
 *   1. Ions\Auth\Contracts\Authenticatable::getAuthIdentifier()
 *   2. getKey() — duck-typed, covers Eloquent/Sentinel models
 *   3. A readable ->id property/attribute
 * Nothing resolvable => LogicException, nothing inserted.
 */
final class DatabaseChannel implements Channel
{
    public function send(object $notifiable, Notification $notification): void
    {
        // Resolve id + payload BEFORE touching the table so a failure
        // inserts nothing.
        $notifiableId = $this->resolveNotifiableId($notifiable);
        $data = json_encode($notification->toDatabase($notifiable), JSON_THROW_ON_ERROR);

        $this->connection()->table($this->table())->insert([
            'id' => (string) Str::uuid(),
            'notifiable_id' => (string) $notifiableId,
            'notifiable_type' => $notifiable::class,
            'type' => $notification::class,
            'data' => $data,
            'read_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function resolveNotifiableId(object $notifiable): string|int
    {
        if ($notifiable instanceof Authenticatable) {
            return $notifiable->getAuthIdentifier();
        }

        if (method_exists($notifiable, 'getKey')) {
            $key = $notifiable->getKey();

            if (is_string($key) || is_int($key)) {
                return $key;
            }
        }

        // isset() (not property_exists) so Eloquent virtual attributes count.
        if (isset($notifiable->id)) {
            $id = $notifiable->id;

            if (is_string($id) || is_int($id)) {
                return $id;
            }
        }

        throw new LogicException(sprintf(
            'Database notification cannot be stored: the notifiable [%s] resolves no id — implement '
            . 'Ions\Auth\Contracts\Authenticatable (getAuthIdentifier()), a getKey() method, or an ->id property.',
            $notifiable::class
        ));
    }

    private function table(): string
    {
        return (string) config('notifications.table', 'notifications');
    }

    private function connection(): \Illuminate\Database\Connection
    {
        $capsule = Kernel::app()->get('db');

        if (!$capsule instanceof Manager) {
            throw new RuntimeException(
                "The notifications 'database' channel needs the Eloquent capsule: enable the 'db' engine in config('app.database_engine')."
            );
        }

        return $capsule->getConnection();
    }
}
