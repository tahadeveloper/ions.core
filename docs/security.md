# Encryption & signed URLs

Two explicit-use security facilities, both derived from `APP_KEY`:

- `Ions\Security\Encrypter` — authenticated encryption (sodium XChaCha20-Poly1305).
- `Ions\Security\UrlSigner` — tamper-proof, optionally expiring URLs, with the
  `signedRoute()`/`signedUrl()` helpers and the `signed` middleware.

Both are bound lazily by `SecurityProvider` (`encrypter` / `url.signer`); a
normal request never resolves them, so they add zero hot-path cost when
unused. Resolving either **without a valid `APP_KEY` (≥ 32 bytes)** throws a
`RuntimeException` naming `APP_KEY` — the same length rule the JWT signer
uses.

## Key derivation

`APP_KEY` is never used directly. Each facility derives its own 32-byte key
via HKDF-SHA256 with a distinct info string (`ions.encrypter.v1` /
`ions.urlsigner.v1`), so encryption, URL signing, and JWT signing never share
key material. Rotating `APP_KEY` invalidates all previously encrypted
payloads and signed URLs.

## Encrypter

```php
$encrypter = app('encrypter');           // Ions\Security\Encrypter

$payload = $encrypter->encrypt('secret');   // "iev1:" + url-safe base64
$plain   = $encrypter->decrypt($payload);   // 'secret'
```

- Payload format: `iev1:` + url-safe base64(24-byte nonce ‖ ciphertext+tag).
  The payload is URL- and cookie-safe as-is.
- `decrypt()` throws `Ions\Security\DecryptException` on any failure —
  tampered ciphertext, wrong key, truncated/garbage input, or an unknown
  prefix. Catch it; never treat the message as user-visible content.
- Encrypt structured data by JSON-encoding it first:
  `$encrypter->encrypt(json_encode($data, JSON_THROW_ON_ERROR))`.

## Signed URLs

```php
// routes/web.php — name the route and guard it with the 'signed' alias:
Route::get('/unsubscribe/{user}', Controller::class . '::unsubscribe', [], 'unsubscribe')
    ->middleware(['signed']);

// Anywhere in the app — generate a tamper-proof link:
$url = signedRoute('unsubscribe', ['user' => $user->id]);                // never expires
$url = signedRoute('unsubscribe', ['user' => $user->id],
                   new DateTimeImmutable('+1 day'));                      // DateTimeInterface
$url = signedRoute('unsubscribe', ['user' => $user->id], time() + 3600); // epoch seconds
```

- `signedRoute(string $name, array $params = [], DateTimeInterface|int|null $expiresAt = null): string`
  resolves the named route (route files, attribute routes, and
  `Kernel::RouteCollection()` are all searched), substitutes placeholders,
  appends extra params to the query string, and returns an **absolute URL**
  built from `app.app_url` — consistent with the host-header gate.
- `signedUrl(string $url, $expiresAt = null)` signs an arbitrary URL string.
- The signature is an HMAC-SHA256 over the path plus the **sorted** query
  params (excluding `signature` itself), so query-param reordering does not
  break verification. Expiry rides in an `expires` epoch param that is part
  of the signed data — stripping or extending it invalidates the signature.

### The `signed` middleware

`Ions\Http\Middleware\ValidateSignatureMiddleware` rejects requests whose URL
fails verification (missing/tampered signature, or expired) with **403** via
the standard exception handler. Register the alias in `config/app.php`:

```php
'middleware_aliases' => [
    'signed' => \Ions\Http\Middleware\ValidateSignatureMiddleware::class,
],
```

then attach `->middleware(['signed'])` per route (the skeleton ships the
alias commented).

### Typical uses

Email verification and unsubscribe links: generate with
`signedRoute('verify.email', ['user' => $id], new DateTimeImmutable('+48 hours'))`, guard
the route with `signed`, and the link is self-authenticating — no token
table required. Anything secret inside the URL should additionally be
encrypted with the `Encrypter` (signatures prove integrity, not
confidentiality).
