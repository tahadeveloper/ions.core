# Email verification

`Ions\Auth\EmailVerification` issues **signed, expiring links bound to the
user's current email** and marks the user verified when the link is confirmed.
It is built on the URL signer ([docs/security.md](security.md)) and the
notification pipeline ([docs/notifications.md](notifications.md)); the host owns
the `email_verified_at` column.

## The contract

Your user model opts in by implementing `Ions\Auth\Contracts\VerifiesEmail`:

```php
use Ions\Auth\Contracts\VerifiesEmail;

class User extends Model implements VerifiesEmail
{
    public function getEmailForVerification(): string { return $this->email; }
    public function getKeyForVerification(): string|int { return $this->id; }
    public function hasVerifiedEmail(): bool { return $this->email_verified_at !== null; }
    public function markEmailVerified(): void { $this->forceFill(['email_verified_at' => now()])->save(); }
    public function getEmailVerifiedAt(): ?\DateTimeInterface { return $this->email_verified_at; }
}
```

A model that does **not** implement the interface is never gated — verification
is strictly opt-in.

### Migration

Copy the stub at `src/Auth/stubs/email_verified_at_column.stub` into your host
`src/Database` (or `app/Database`) migrations and adjust the table name. It adds
a nullable `email_verified_at` timestamp.

## The flow

```
register ──▶ EmailVerification::sendVerification($user)   (mails a signed link)
                                   │
            user clicks the link ──▶ GET /email/verify?id=..&hash=..&expires=..&signature=..
                                   │   ('signed' middleware validates the HMAC + expiry)
            controller calls ──────▶ EmailVerification::verify($request, $user)
                                   │   (re-checks signature/expiry, id, and the email hash)
                                   └─▶ markEmailVerified()  →  redirect / 200
```

### Sending

```php
// Throttled per email (config app.auth.verify_throttle); returns false if throttled.
EmailVerification::sendVerification($user);

// Or send the notification directly (no throttle):
notify($user, new \Ions\Auth\Notifications\VerifyEmail());
```

`VerifyEmail` is a mail notification. Its `VerifyEmailMail` is recipient-less —
the mail channel routes it from the user's `->email`/`getEmail()`. The default
body carries the link inline; subclass `VerifyEmailMail` (or send your own
Mailable from a custom notification) for a branded template. The `From` is the
host's `MAIL_FROM_ADDRESS`.

### The signed link

```php
$url = EmailVerification::verificationUrl($user); // 60 min default
// /email/verify?id=7&hash=<sha256(email)>&expires=<epoch>&signature=<hmac>
```

`verificationUrl()` points at a named route (default `verification.verify`);
register one — **with no path placeholders**, so `id`/`hash` ride as signed
query parameters:

```php
Route::get('/email/verify', VerifyController::class . '@verify', [], 'verification.verify')
    ->middleware(['signed']);
```

### Verifying

```php
class VerifyController
{
    public function verify(Request $request)
    {
        $user = $request->attributes->get('auth_user'); // or however you resolve it

        if (EmailVerification::verify($request, $user)) {
            return redirect()->to('/dashboard');
        }

        abort(403, 'Invalid or expired verification link.');
    }
}
```

`verify()` **returns `true` and calls `markEmailVerified()` on success** — it is
idempotent (an already-verified user returns `true` without re-marking). It
returns `false`, and never marks, when:

- the signature or expiry fails (re-checked via `UrlSigner` over the request
  URI, defensively — even though the `signed` middleware already validated it),
- the link's `id` does not equal `getKeyForVerification()`, or
- the link's `hash` does not equal `sha256` of the user's **current** email
  (compared with `hash_equals`, timing-safe).

## Security: the hash binds to the current email

The link carries `hash = sha256(email)` computed at issue time. `verify()`
recomputes it from the user's **live** email and constant-time compares. So if
the user changes their email after the link was sent, the old link no longer
verifies — a verification link only ever confirms the address it was issued for.
The signer's HMAC (over path + sorted query) makes `id`, `hash`, and `expires`
tamper-proof.

## Gating routes — the `verified` middleware

`Ions\Auth\Http\EnsureEmailVerified` (alias `verified`) blocks an authenticated
but unverified user. Register the alias (the skeleton config already does):

```php
// config/app.php
'middleware_aliases' => [
    'verified' => \Ions\Auth\Http\EnsureEmailVerified::class,
],

Route::get('/dashboard', ...)->middleware(['verified']);
```

It inspects the request's `auth_user` attribute (set by `AuthMiddleware`) and
gates only when that user implements `VerifiesEmail` and is unverified —
content-negotiated like the framework's other gates:

- **API/JSON** requests (`wantsJson()` or a path under `/api`) get a **403**.
- **Web** requests are **redirected** to
  `config('app.auth.email_verification_redirect', '/email/verify')`.

A missing user, or a user model that does not implement the contract, passes
straight through.

## Resend throttle

`sendVerification()` applies a per-email limiter (the same pattern as
forgot-password), backed by the shared cache:

```php
'auth' => [
    'verify_throttle' => ['max' => 3, 'decay' => 600], // 3 per 10 minutes
],
```

Over the limit, `sendVerification()` returns `false` and sends nothing.

## See also

- [docs/security.md](security.md) — `UrlSigner`, `signedRoute()`, the `signed` middleware.
- [docs/notifications.md](notifications.md) — the notification pipeline and the mail channel.
- [docs/auth.md](auth.md) — `AuthMiddleware`, the `auth_user` attribute, JWT.
- [docs/two-factor.md](two-factor.md) — the companion TOTP second factor.
