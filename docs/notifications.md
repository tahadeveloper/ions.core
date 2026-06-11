# Notifications

A notification tells ONE notifiable (usually a user) about ONE event over one
or more channels. `Ions\Notifications\Notification` is the base class;
built-in channels are **mail** (delivers a [`Mailable`](mail.md)) and
**database** (persists a JSON payload row). `Ions\Providers\NotificationProvider`
binds the dispatcher as a lazy `notifications` singleton — nothing resolves on
a normal request.

> A built-in mail notification: `Ions\Auth\Notifications\VerifyEmail` delivers a
> signed email-verification link — see [email-verification.md](email-verification.md).

## Defining a notification

Subclasses take their data through the constructor, declare target channels in
`via()`, and implement one `to{Channel}()` payload method per requested
channel:

```php
use Ions\Mail\Mailable;
use Ions\Notifications\Notification;

final class OrderShipped extends Notification
{
    public function __construct(private int $orderId) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): Mailable
    {
        return new OrderShippedMail($this->orderId);   // a normal Ions Mailable
    }

    public function toDatabase(object $notifiable): array
    {
        return ['order_id' => $this->orderId];         // stored json-encoded
    }
}

notify($user, new OrderShipped($order->id));
```

`notify($notifiable, $notification)` is the global helper; the static
equivalent is `Ions\Support\Notifications::send($notifiable, $notification)`.
Both resolve the container `notifications` binding, so
[`Notifications::fake()`](#faking--assertions) intercepts either path.

Requesting a channel in `via()` without implementing its payload method throws
a `LogicException` naming the notification class and the missing method —
nothing is silently skipped. A `via()` returning `[]` delivers nowhere (and
throws nothing).

## The mail channel

`toMail()` returns a regular `Ions\Mail\Mailable`, sent through the container
`mailer` exactly like `$mailable->send()` — so `Mail::fake()` records it and
`Mail::assertSent(OrderShippedMail::class)` FQCN matching works end-to-end
through `notify()`.

**Recipient routing.** An explicit `to()`/`cc()`/`bcc()` declared in the
Mailable's `build()` always wins. Only a recipient-less Mailable is routed
from the notifiable, walking this chain (each step falls through when it
yields nothing):

1. `routeNotificationForMail()` — duck-typed; implementing the optional
   `Ions\Notifications\Notifiable` interface documents the contract. May
   return a single address, a list, or an `address => display-name` map (the
   same shapes `Mailable::to()` accepts).
2. A `getEmail(): string` getter.
3. A readable `->email` property — checked with `isset()`, so Eloquent and
   Sentinel users (whose attributes answer `__isset`/`__get`) participate.

Nothing resolvable → `LogicException` naming the chain; no half-built mail
reaches the mailer.

```php
use Ions\Notifications\Notifiable;

final class Customer implements Notifiable
{
    public function routeNotificationForMail(): string|array
    {
        return [$this->contactEmail => $this->displayName];
    }
}
```

## The database channel

`toDatabase()` returns a serializable array; the channel inserts one row per
notification through the `db` capsule (default connection) into
`config('notifications.table', 'notifications')`:

| Column | Value |
|---|---|
| `id` | UUID, generated per insert (`Str::uuid`) |
| `notifiable_type` | FQCN of the notifiable |
| `notifiable_id` | see the resolution chain below |
| `type` | FQCN of the notification |
| `data` | `json_encode(toDatabase($notifiable))` |
| `read_at` | `null` — hosts mark rows read by setting it |
| `created_at` | insert time |

**Notifiable id resolution** (first hit wins): `Ions\Auth\Contracts\Authenticatable::getAuthIdentifier()`
→ a duck-typed `getKey()` (Eloquent/Sentinel models) → a readable `->id`
property. Nothing resolvable → `LogicException`, nothing inserted.

### Table DDL

A migration stub ships at
`src/Notifications/stubs/create_notifications_table.stub` — copy it into the
host's `database/schemas/` directory (4.4+ layout; `{app|src}/Database/Schema`
on the legacy layout), dropping `.stub`, and run
`ions migrate`, the same mechanism as the [jobs-table stub](cache-queue-events.md).
To use a different table name, rename it in the migration **and** set
`config('notifications.table')`.

## Custom channels

Map a name to a class implementing `Ions\Notifications\Contracts\Channel` in
`config('notifications.channels')`; host entries merge over the built-ins
(an entry named `mail`/`database` replaces the built-in). Instances are built
through the container, so constructor dependencies the host has bound are
injected.

```php
// config/notifications.php
return [
    'channels' => ['slack' => \App\Notifications\SlackChannel::class],
];

final class SlackChannel implements \Ions\Notifications\Contracts\Channel
{
    public function send(object $notifiable, Notification $notification): void
    {
        // e.g. call $notification->toSlack($notifiable) by your own convention
    }
}
```

An unmapped channel name in `via()` throws a `LogicException` listing the
known channels.

## Deferred (queued) delivery

`notify()` is synchronous by design — there is no `Notification::queue()`.
Defer delivery one of two ways:

- **Mail:** queue the underlying Mailable — have the host dispatch
  `(new OrderShippedMail($id))->queue('database')` directly, or wrap the
  notify call below.
- **Any channel:** wrap `notify()` in a host [`Ions\Queue\Job`](cache-queue-events.md)
  whose `handle()` calls `notify($user, new OrderShipped($id))` on the worker.

## Faking & assertions

`Ions\Support\Notifications::fake()` swaps the `notifications` binding for a
recording `Ions\Testing\Fakes\NotificationFake` implementing the same
`Ions\Notifications\Contracts\Dispatcher` contract — `notify()` records there
and **no channel runs** (no mail is materialized, no row inserted). See
[testing.md](testing.md#ionssupportnotificationsfake) for the assertion
reference:

```php
use Ions\Support\Notifications;

Notifications::fake();

notify($user, new OrderShipped(1001));

Notifications::assertSentTo($user, OrderShipped::class);
Notifications::assertSentToTimes($user, OrderShipped::class, 1);
Notifications::assertNothingSent();   // fails here, of course
```
