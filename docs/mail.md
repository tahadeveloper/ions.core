# Mail

Outbound mail is Symfony Mailer under the hood. `Ions\Providers\MailProvider`
binds a lazy `mailer` service (an SMTP transport built from the `MAIL_USERNAME`
/ `MAIL_PASSWORD` / `MAIL_HOST` / `MAIL_PORT` env vars on first resolve — a
missing DSN only errors at send time). Everything in this document routes
through that one binding, which is also what makes `Mail::fake()` work.

## Mailable classes

`Ions\Mail\Mailable` is the base class for host-app mail messages. A subclass
takes its data through the constructor and implements `build()`, which
configures the message with protected fluent helpers:

```php
use Ions\Mail\Mailable;

final class WelcomeMail extends Mailable
{
    public function __construct(
        private string $email,
        private string $name,
    ) {}

    public function build(): void
    {
        $this->to($this->email)
            ->subject('Welcome!')
            ->view('emails/welcome.twig', ['name' => $this->name]);
    }
}

(new WelcomeMail($email, $name))->send();              // send now
(new WelcomeMail($email, $name))->queue('database');   // send from a worker
```

### build() helpers

| Helper | Notes |
|---|---|
| `to($addresses)` / `cc($addresses)` / `bcc($addresses)` / `replyTo($addresses)` | A single address string, a list of addresses, or an `'address' => 'Display Name'` map (mixed entries are fine); repeated calls append |
| `from(string $address, ?string $name = null)` | Optional — defaults to `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` env, the same fallback `newMailerDsn()` uses |
| `subject(string $subject)` | Optional (Symfony allows subject-less mail), but recommended |
| `html(string $html)` | Literal HTML body |
| `text(string $text)` | Plain-text body; can accompany an HTML/view body |
| `view(string $template, array $data = [])` | Twig template as the HTML body, rendered at **send time** through the shared `view` factory (`app.twig.*` config). Takes precedence over `html()` |
| `attach(string $path, ?string $name = null, ?string $mime = null)` | File attachment; name/mime default to what Symfony infers from the path |

All helpers return `$this`, so `build()` reads as one fluent chain.

### Public API

- **`send(): void`** — builds, renders the view (if any), materializes a
  Symfony `Email`, and hands it to the container `mailer`. `Mail::fake()`
  therefore intercepts it.
- **`queue(?string $connection = null, ?string $queue = null): mixed`** —
  wraps the mailable in an `Ions\Mail\SendMailableJob` and dispatches it via
  the `dispatch()` helper. Returns the driver's push result (database job id;
  the `sync` driver runs immediately and returns `0`).
- **`toSymfonyEmail(): Email`** — build + materialize without sending. Useful
  in tests and for notification channels.

### Validation

`toSymfonyEmail()` (and therefore `send()`) throws
`Ions\Mail\InvalidMailableException` — mirroring what Symfony Mailer would
reject at send time, with clearer messages — when the built mailable has:

- no recipient (none of `to`/`cc`/`bcc`),
- no from address (no `from()` call and `MAIL_FROM_ADDRESS` unset/empty), or
- no body (none of `view`/`html`/`text` and no attachment).

The subject is optional.

One exception to the recipient rule: `routeTo()` (public) injects **fallback**
To recipients from outside `build()` — applied only when `build()` declares no
to/cc/bcc of its own. The [notifications mail channel](notifications.md) uses
it to route recipient-less mailables to a notifiable; an explicit recipient in
`build()` always wins.

## Queueing & serialization

`queue()` does **not** build or render anything. The `SendMailableJob` carries
the mailable as plain serialized state — on the `database` driver that is
literally `serialize($job)` in the payload — and `handle()` calls
`$mailable->send()` on the worker, which is where `build()` runs and the Twig
view renders.

Consequences for mailable constructor state:

- Keep it plain: ids, scalars, arrays. No open resources, closures, or
  container-bound services.
- `SerializesModels` on the job only transforms the job's **own** top-level
  properties; an Eloquent model nested inside your mailable is serialized
  whole (heavy, and stale by the time the worker runs). Pass ids and re-query
  in `build()` instead.
- Because rendering happens in the worker, the worker process needs the same
  `app.twig.*` config and templates available.

```php
use Ions\Support\Queue;
use Ions\Mail\SendMailableJob;

Queue::fake();
(new WelcomeMail($email, $name))->queue('database', 'emails');
Queue::assertDispatched(SendMailableJob::class);
```

## The Mail facade

`Ions\Support\Mail` accepts both Symfony messages and mailables:

```php
use Ions\Support\Mail;

Mail::send($email);                       // Symfony RawMessage (+ optional Envelope) — unchanged
Mail::send(new WelcomeMail($e, $n));      // Mailable overload (same as ->send())
Mail::queue(new WelcomeMail($e, $n), 'database', 'emails');   // same as ->queue()
```

The `?Envelope` second argument only applies to the RawMessage path. A
mailable computes its envelope from the message itself, so passing an
`Envelope` together with a `Mailable` throws an `InvalidArgumentException`
(rather than silently dropping it).

## Faking & assertions

`Mail::fake()` swaps the `mailer` binding for a recorder, so `$mailable->send()`
— and a queued mailable's `handle()` on the `sync` driver — records instead of
hitting SMTP.

Every email a mailable materializes is stamped with an
**`X-Ions-Mailable: <FQCN>`** header (`Mailable::CLASS_HEADER`). That is how
`assertSent()` matches mailable class-strings — no fake-side state, and the
header is visible/testable on `toSymfonyEmail()` directly. The header is
**test-only metadata**: `send()` keeps it when the resolved mailer is the
recording `MailFake` and strips it before any real mailer, so the class name
never reaches actual recipients. Matching is inheritance-aware (like
`instanceof`): `Mail::assertSent(BaseMail::class)` also matches a sent
subclass of `BaseMail`.

```php
Mail::fake();

(new WelcomeMail('ion@example.test', 'Ion'))->send();

Mail::assertSent(WelcomeMail::class);                       // matches via the header
Mail::assertSent(fn (Email $email) => $email->getSubject() === 'Welcome!');
Mail::assertSentCount(1);
```

A class-string filter still also matches Symfony message classes by
`instanceof` (e.g. `Mail::assertSent(Email::class)`), exactly as before. See
[testing.md](testing.md#ionssupportmailfake) for the full assertion table.

## `newMailerDsn()` (unchanged)

The pre-Mailable helper keeps working exactly as documented — build an
`Email` from `(array|string $emails, string $subject, $body)`, from-address
from `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME`, send through the container
`mailer`, return `bool` and log failures to `send_mail.log`. It records
through `Mail::fake()` the same way mailables do (minus the FQCN header).
Mailables are the recommended path for new code; there is no deprecation.
