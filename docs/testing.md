# Testing host applications

`Ions\Testing\TestCase` is the framework's host-facing test kit: subclass it
in your application, point it at your app root, and drive the full HTTP stack
in-process — real kernel boot, real routing, real middleware (auth, CSRF,
security headers), no web server. Every request returns an
`Ions\Testing\TestResponse` with fluent, chainable assertions.

The framework's own suite uses the exact same machinery
(`tests/Feature/Testing/TestCaseTest.php` is a working reference).

## Subclassing `TestCase`

Set the protected `$basePath` property to your application root — the
directory containing `config/`, `routes/` and `.env` (in the
[skeleton layout](skeleton.md) that is the project root):

```php
<?php

declare(strict_types=1);

namespace Tests;

abstract class AppTestCase extends \Ions\Testing\TestCase
{
    // tests/ lives directly under the app root in the skeleton layout
    protected string $basePath = __DIR__ . '/..';
}
```

> A shared base class like this must be **autoloadable**: the `namespace
> Tests;` above relies on the `autoload-dev` PSR-4 mapping the skeleton ships
> (`"Tests\\": "tests/"` in `composer.json`). Plain test files themselves
> don't need it — PHPUnit includes every `*Test.php` directly, which is why
> `make:test` generates namespace-free tests extending
> `Ions\Testing\TestCase` (see `skeleton/tests/ExampleTest.php`).

Override `protected function basePath(): string` instead when the path needs
to be computed. An unset or non-existent path throws a `RuntimeException`
explaining what to set.

Lifecycle — handled for you, per test:

- `setUp()` snapshots `$_ENV`/`$_SERVER` (booting loads your `.env` into
  them), then `Kernel::resetForTesting()` + `Kernel::boot($basePath)`.
- `tearDown()` resets the kernel again and restores the env superglobals, so
  no framework or env state ever leaks between tests.

When you override `setUp()` or `tearDown()`, **always call the parent** —
`parent::setUp()` first, `parent::tearDown()` last:

```php
protected function setUp(): void
{
    parent::setUp();          // boots the kernel — required first

    $this->seedDatabase();    // your per-test setup, kernel available
}

protected function tearDown(): void
{
    $this->cleanupFiles();    // your teardown, kernel still booted

    parent::tearDown();       // resets the kernel — required last
}
```

If you forget `parent::setUp()`, any request helper fails fast with
`RuntimeException: Kernel not booted — did you forget parent::setUp() in your
setUp() override?` instead of a confusing fatal deep inside the kernel.

> Tips: like the framework's own suite, point your test `.env` at in-memory
> drivers (`SESSION_DRIVER=array`, SQLite `:memory:`, array cache/queue) so
> tests stay fast and hermetic. Also set **`APP_DEBUG=true`** in the test
> `.env`: boot/config errors then carry their real cause, and the kit's
> testing mode guarantees they are **thrown** (reported by the runner as a
> normal failure) rather than ending the process.

## Making requests

```php
$this->get('/users', headers: ['Accept-Language' => 'de']);
$this->post('/users', ['name' => 'Ion']);          // form-encoded parameters
$this->put('/users/7', ['name' => 'Ion']);
$this->patch('/users/7', ['name' => 'Ion']);
$this->delete('/users/7');

// JSON body + Content-Type/Accept: application/json
$this->json('POST', '/api/users', ['name' => 'Ion', 'tags' => ['a', 'b']]);
```

Each helper builds an `Ions\Support\Request`, passes it through
`Kernel::handle()` (the same pipeline production traffic takes) and returns a
`TestResponse`. Header names are translated to the server keys
`Request::create` expects (`X-Custom` → `HTTP_X_CUSTOM`, `Content-Type` →
`CONTENT_TYPE`), so the request's header bag and server bag always agree.

The low-level escape hatch mirrors Laravel's positional `call()` signature —
use it for custom verbs, raw bodies, cookies or file uploads:

```php
public function call(
    string $method,
    string $uri,
    array $parameters = [],   // query/form parameters
    array $cookies = [],      // cookie name => value
    array $files = [],        // file uploads (Symfony UploadedFile instances)
    array $server = [],       // raw server keys: HTTP_X_CUSTOM, CONTENT_TYPE, …
    ?string $content = null,  // raw request body
): TestResponse;

$this->call('PURGE', '/cache/users');
$this->call('POST', '/upload', [], [], ['avatar' => $uploadedFile]);
$this->call(
    'POST',
    '/api/import',
    server: ['CONTENT_TYPE' => 'text/csv'],
    content: "id,name\n1,Ion",
);
```

## Authentication: `actingAs()`

`actingAs()` issues a **real JWT** through the kernel's configured signer and
sends it as `Authorization: Bearer …` on every subsequent request — exactly
the token `AuthMiddleware` verifies. It accepts an
`Ions\Auth\Contracts\Authenticatable` or a plain user id, plus optional extra
claims:

```php
$this->actingAs($user)->get('/api/profile')->assertOk();
$this->actingAs('user-99', ['scope' => 'admin'])->get('/api/admin')->assertOk();
```

**Requires `APP_KEY`** in the application's `.env`: a random secret of at
least 32 bytes (e.g. a 64-character hex string —
`php -r "echo bin2hex(random_bytes(32));"`). Without it, JWT signing is
disabled and `actingAs()` throws a `RuntimeException` telling you what to set.

Default headers (manual control):

```php
$this->withToken($jwtString);                  // Authorization: Bearer …
$this->withHeaders(['X-Tenant' => 'acme']);    // merged into every request
$this->flushHeaders();                         // reset (also happens per test)
```

Stored defaults ride along on every subsequent request; per-call headers win
on conflict. Stored header names are lowercased, so setting the same header
with different casing (`AUTHORIZATION` vs `Authorization`) overrides the
previous value instead of storing a duplicate.

## `TestResponse` assertions

All assertions delegate to PHPUnit (failures report normally) and return
`$this` for chaining.

| Assertion | Verifies |
|---|---|
| `assertStatus(int $code)` | Exact status code |
| `assertOk()` / `assertCreated()` / `assertNoContent()` | 200 / 201 / 204 (204 also asserts an empty body) |
| `assertRedirect(?string $to = null)` | Redirect response; optionally the exact `Location` |
| `assertSee(string $value, bool $escape = true)` | Body contains the string — **HTML-escaped by default** (Laravel semantics: what a template renders for `$value` must appear); pass `escape: false` for a raw contains check |
| `assertJson(array $subset)` | Decoded body contains the array as a **recursive subset**: every given key must exist with a matching value, nested arrays recurse, lists match by index, extra response keys are ignored |
| `assertJsonPath(string $dotPath, mixed $expected)` | Value at a dot path (`data.user.id`, `tags.1`) is **identical** (`assertSame`) to `$expected` |
| `assertHeader(string $name, ?string $value = null)` | Header presence, optionally its exact value |

Failure messages from `assertStatus` (and the Ok/Created/NoContent shortcuts),
`assertSee`, `assertJson` and `assertJsonPath` include the response body —
pretty-printed when it is JSON, truncated to ~500 characters — so a failing
CI run shows *what the app actually said* without re-running with dumps.

Known wart: `assertJsonPath($path, null)` cannot distinguish a path whose
value is `null` from a path that is **absent** — both decode to `null`. Use
`assertJson(['key' => null])` when you need the key to actually exist.

Accessors: `status()`, `content()`, `headers()`,
`json(?string $key = null)` (full decoded body, or dot-path access; `null`
when absent or not JSON), and the public `baseResponse` property — the
underlying Symfony response, as an escape hatch.

## Fakes: Queue, Event, Storage, Mail, Notifications, Http

Each framework service can be swapped for a recording fake with a single
static call. `::fake()` rebinds the service in the container and returns the
fake; assertions work on the returned instance **and** (for
Queue/Event/Mail/Notifications/Http) as static passthroughs on the same facade. Because every test boots a fresh
container, an installed fake never leaks into the next test — there is
nothing to tear down.

Calling a static assertion without having installed the fake first throws a
`RuntimeException` telling you to call `::fake()`.

Every assertion on a fake instance returns the fake itself, so assertions
chain:

```php
$fake->assertDispatched(SendWelcomeEmail::class)
    ->assertNotDispatched(ChargeCard::class);
```

### `Ions\Support\Queue::fake()`

Jobs dispatched through the `dispatch()` helper are recorded instead of run.

```php
use Ions\Support\Queue;

Queue::fake();                 // optional: Queue::fake([OnlyThisJob::class])

dispatch(new SendWelcomeEmail($user));

Queue::assertDispatched(SendWelcomeEmail::class);
```

| Assertion | Verifies |
|---|---|
| `assertDispatched(string $job, callable\|int\|null $filterOrCount = null)` | Job class was dispatched; with a callable, at least one dispatched job satisfies it; with an int, the exact count |
| `assertDispatchedTimes(string $job, int $times = 1)` | Exact dispatch count |
| `assertNotDispatched(string $job, ?callable $filter = null)` | Job class was not dispatched (or none matching the filter) |
| `assertNothingDispatched()` | No jobs were dispatched at all |

The fake extends Illuminate's `QueueFake`, so its native `assertPushed*`
family is available too.

### `Ions\Support\Event::fake(?array $eventsToFake = null)`

Events are recorded instead of reaching their listeners. With a list of
event names, only those are intercepted — every other event still fires
normally. The kernel's own lifecycle events (e.g.
`Ions\Events\RequestHandled`) are intercepted like any other.

```php
use Ions\Support\Event;

$events = Event::fake();

$this->post('/orders', [...])->assertCreated();

$events->assertFired(OrderPlaced::class, fn (OrderPlaced $e) => $e->order->total === 99);
Event::assertNotFired(OrderCancelled::class);
```

| Assertion | Verifies |
|---|---|
| `assertFired(string $event, ?callable $filter = null)` | Event fired (filter receives the event object) |
| `assertFiredTimes(string $event, int $times = 1)` | Exact fire count |
| `assertNotFired(string $event, ?callable $filter = null)` | Event did not fire (or none matching the filter) |
| `assertNothingFired()` | No events were recorded at all |

### `Ions\Filesystem\Storage::fake(?string $disk = null)`

Swaps the named disk (default: `config('filesystem.default')`) for a fresh
**in-memory** disk — forced regardless of the configured driver, so a disk
configured as `s3` or `local` never sees test writes. Files written through
normal `Storage` calls land in the fake; assertions live on the returned
handle.

> **Warning — what `Storage::fake()` does and does not intercept.**
> `Storage::fake()` covers `Ions\Filesystem\Storage` — the disks resolved
> through the container's `filesystem.manager` — **only**. The legacy
> `Ions\Bundles\IonDisk` and `Ions\Bundles\IonUpload` helpers, and the
> Illuminate-facade shim `Ions\Support\Storage`, are **not** intercepted:
> code going through them in a test will hit the real local disk (or S3).
>
> **Import trap:** the correct import is `use Ions\Filesystem\Storage;`.
> `use Ions\Support\Storage;` is a different class (an Illuminate facade
> shim) — its `::fake()` is Laravel's facade fake and fails confusingly in
> an Ions app because there is no Laravel application behind it.

```php
use Ions\Filesystem\Storage;

$disk = Storage::fake();           // or Storage::fake('s3')

Storage::put('avatars/7.png', $bytes);

$disk->assertStored('avatars/7.png');
$disk->assertMissing('avatars/8.png');
```

| Assertion | Verifies |
|---|---|
| `assertStored(string $path)` / `assertExists(string $path)` | File exists on the fake disk |
| `assertMissing(string $path)` | File does not exist on the fake disk |

`$disk->disk()` exposes the underlying Flysystem instance (the same one
`Storage::disk()` now resolves) for direct inspection.

### `Ions\Support\Mail::fake()`

Replaces the `mailer` binding (Symfony Mailer) with a recorder implementing
the same `MailerInterface`, so anything sending through the container —
`Ions\Mail\Mailable::send()` and the `newMailerDsn()` helper included —
records instead of opening an SMTP connection. Hosts send Symfony `Email`
objects; filter callables receive the message plus the recorded
`Symfony\Component\Mailer\Envelope` as a second argument (`null` when the
sender did not pass one).

A class-string filter matches two ways: Symfony message classes by
`instanceof`, and `Mailable` FQCNs via the `X-Ions-Mailable` header every
mailable stamps on the email it materializes (see
[mail.md](mail.md#faking--assertions)) — so `Mail::assertSent(ResetPasswordMail::class)`
works even though what the fake records is a Symfony `Email`. The header
match is inheritance-aware, like `instanceof`: asserting a base Mailable
class also matches sends of its subclasses. Real (non-fake) sends strip the
header before it reaches the transport, so it never ships to recipients.

```php
use Ions\Support\Mail;
use Symfony\Component\Mime\Email;

$mailer = Mail::fake();

$this->post('/password/forgot', ['email' => 'ion@example.test'])->assertOk();

Mail::assertSent(ResetPasswordMail::class);   // Mailable FQCN (header match)
Mail::assertSent(fn (Email $email) => $email->getSubject() === 'Reset your password');
$mailer->assertSentCount(1);
```

| Assertion | Verifies |
|---|---|
| `assertSent(string\|callable\|null $filter = null)` | At least one mail sent; a class-string requires an instance of that message class **or** a mail materialized by that `Mailable` class (or one of its subclasses), a callable receives `($message, ?Envelope $envelope)` and must match at least one |
| `assertSentCount(int $count)` | Exact number of sent mails |
| `assertNothingSent()` | No mails were sent at all |

`$mailer->sent()` returns every recorded message in send order;
`$mailer->sentEnvelopes()` returns the index-aligned list of envelopes
(`null` entries where no explicit envelope was passed).

### `Ions\Support\Notifications::fake()`

Replaces the `notifications` binding (the
[notification dispatcher](notifications.md)) with a recorder implementing the
same `Ions\Notifications\Contracts\Dispatcher` contract, so `notify()` and
`Notifications::send()` record instead of running any channel — no mail is
materialized and no database row is inserted (note the channel side: with only
`Mail::fake()` installed, a mail notification still runs the channel and is
asserted via `Mail::assertSent(TheMailable::class)`; with
`Notifications::fake()` the channel never runs).

Class-string matching is inheritance-aware, like `instanceof`. Notifiable
matching accepts a different instance than the one notified: two notifiables
match when they are the same object, or the same class and the same identity —
compared via `Authenticatable::getAuthIdentifier()`, a duck-typed `getKey()`
(Eloquent/Sentinel models), or a readable `->id`, in that order.

```php
use Ions\Support\Notifications;

$fake = Notifications::fake();

notify($user, new OrderShipped(1001));

Notifications::assertSentTo($user, OrderShipped::class);
Notifications::assertSentTo($user, OrderShipped::class,
    fn (OrderShipped $n, object $notifiable) => $notifiable === $user);
$fake->assertSentToTimes($user, OrderShipped::class, 1);
```

| Assertion | Verifies |
|---|---|
| `assertSentTo(object $notifiable, string $class, ?callable $filter = null)` | At least one `$class` notification sent to `$notifiable`; the filter receives `(Notification, object $notifiable)` and must match at least one |
| `assertSentToTimes(object $notifiable, string $class, int $times)` | Exact per-notifiable, per-class send count |
| `assertNothingSent()` | No notifications were sent at all |

`$fake->sent()` returns every recorded
`['notifiable' => object, 'notification' => Notification]` pair in send order.

### `Ions\Support\Http::fake(callable|array|null $responses = null)`

Replaces the `http` binding (Symfony HttpClient) with a recorder built on
Symfony's own `MockHttpClient`, so nothing leaves the process. With no
argument every request answers 200 with an empty body; pass an associative
array of URL patterns (`*` wildcards) to responses, a sequential list, or a
`MockHttpClient` factory callable. Requests are recorded with the fully
resolved URL and Symfony's processed options.

```php
use Ions\Support\Http;
use Symfony\Component\HttpClient\Response\MockResponse;

$fake = Http::fake([
    'https://api.example.test/*' => new MockResponse('{"id":7}', ['http_code' => 201]),
]);

Http::withToken('secret')->json('https://api.example.test/users', ['name' => 'Amr']);

Http::assertSent('https://api.example.test/*');
$fake->assertSentCount(1);
```

| Assertion | Verifies |
|---|---|
| `assertSent(string\|callable $urlOrFilter)` | At least one request matches; a string is a URL pattern (`*` wildcards), a callable receives `(string $method, string $url, array $options)` |
| `assertSentCount(int $count)` | Exact number of requests sent |
| `assertNothingSent()` | No requests were sent at all |

`$fake->sent()` returns every recorded request in order as
`['method' => ..., 'url' => ..., 'options' => ...]`. Full client and fake
reference: [docs/http-client.md](http-client.md).

## Complete example (skeleton layout)

```php
<?php

declare(strict_types=1);

namespace Tests;

final class ApiTest extends AppTestCase   // see "Subclassing" above
{
    public function test_ping_is_public(): void
    {
        $this->get('/api/ping')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson(['status' => 'success'])
            ->assertJsonPath('data.message', 'pong');
    }

    public function test_profile_requires_a_token(): void
    {
        $this->get('/api/profile')->assertStatus(401);
        $this->withToken('garbage')->get('/api/profile')->assertStatus(401);
    }

    public function test_an_authenticated_user_can_update_their_profile(): void
    {
        $this->actingAs('user-99')
            ->json('PUT', '/api/profile', ['name' => 'Ion'])
            ->assertOk()
            ->assertJson(['data' => ['name' => 'Ion']]);
    }
}
```

Run with Pest or PHPUnit — `Ions\Testing\TestCase` is a plain
`PHPUnit\Framework\TestCase` subclass, so both work unchanged:

```bash
vendor/bin/pest          # or: vendor/bin/phpunit
```
