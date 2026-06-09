# Ions Framework — v2 Upgrade Guide

This document tracks breaking changes and deprecations introduced during the v2 modernisation phases (Phases 1–5). Entries are grouped by area; the phase tag shows when each change was shipped.

---

## Quick-reference checklist

| Area | Action required |
|---|---|
| `APP_KEY` / JWT | Set a ≥ 32-byte `APP_KEY` in `.env` |
| Trusted hosts | Set `app.trusted_hosts` (regex patterns, no delimiters) |
| CSRF | Forms/AJAX must send `_ion_token`; or set `app.csrf.enabled = false` |
| Uploads | Set `app.uploads.allowed` extension list; remove `verot` dependency |
| CSP / security headers | Review/set `app.security.csp` |
| View engine | Port Smarty templates to Twig |
| Database engine | Replace `'redbean'` with `'db'` (Eloquent) |
| Illuminate | Review Eloquent / container deltas (v9 → v11) |
| QueryBuilder filters | Switch to `allowFilters([...])` array-only API |
| ApiController | Add `return` to every `$this->display(...)` / `$this->returnStructure(...)` call |
| Auth backend | Set `config('auth.provider')` (`'sentinel'` or `'eloquent'`) |
| JWT token flow | Use `issueRefresh()` / `refresh()` / `revoke()` for the new token lifecycle |
| Rate limiting | Register `RateLimitMiddleware::class` in `app.middleware_aliases` to use the `throttle` alias |

---

## Phase 5 — Auth Subsystem

### JWT: new token lifecycle (Breaking — Phase 5.4)

The `Jwt` class now issues **two distinct token types**. The API has changed
significantly:

| Method | Signature | Description |
|---|---|---|
| `issue()` | `issue(string $userId, array $claims = []): string` | Mints a short-lived **access** token (`typ=access`). Extra `$claims` are merged in; reserved claims (`typ`, `jti`, `iss`, `aud`, `sub`, `iat`, `nbf`, `exp`) are silently ignored. |
| `issueRefresh()` | `issueRefresh(string $userId): string` | Mints a long-lived **refresh** token (`typ=refresh`, default TTL 14 days). |
| `verify()` | `verify(string $token): Claims` | Validates an **access** token only — rejects refresh tokens. Checks `jti` against the revocation store. |
| `refresh()` | `refresh(string $refreshToken): string` | Exchanges a valid, un-revoked refresh token for a new access token. Rotates (revokes) the presented refresh token so it cannot be reused. |
| `revoke()` | `revoke(string $token): void` | Adds a token's `jti` to the revocation deny-list (works for both access and refresh tokens). No-op when no revocation store is configured. |

**Clock leeway is a constructor parameter, not a `verify()` parameter.** Pass it
when building `Jwt`:

```php
new Jwt(
    secret: config('app.key'),
    issuer: config('app.url'),
    audience: config('app.url'),
    ttlSeconds: config('app.jwt.ttl', 3600),
    clockLeewaySeconds: config('app.jwt.leeway', 0),  // ← leeway here
    revocations: app('revocation_store'),
);
```

Set `app.jwt.leeway` (seconds) in `config/app.php` to tolerate NTP clock skew
between services. The value is read by `Kernel::buildJwt()` and injected into the
constructor automatically.

**`APP_KEY` requirement:** `Jwt` requires a secret of at least 32 bytes. Set
`APP_KEY` in `.env`. The old approach (RSA public key used as an HMAC secret,
tokens that never expired, no user binding) is **gone**. All existing tokens are
invalid after upgrading.

**Revocation store:** the default is a file-cache-backed
`RevocationStore` persisted under `var/cache/revocations`. Bind your own
`RevocationStore` implementation as `'revocation_store'` before `AuthProvider`
runs if you need distributed revocation (e.g. Redis). Configure refresh token
lifetime via `app.jwt.refresh_ttl` (seconds, default 1 209 600 = 14 days).

**Migration:**

```php
// Before (single issue + verify, no refresh/revoke):
$token = $jwt->issue($userId);
$claims = $jwt->verify($token);

// After:
$accessToken  = $jwt->issue($userId);             // short-lived access token
$refreshToken = $jwt->issueRefresh($userId);      // long-lived refresh token

$claims = $jwt->verify($accessToken);             // validates access token only

// Exchange refresh → new access (old refresh token is rotated/revoked):
$newAccessToken = $jwt->refresh($refreshToken);

// Revoke a token explicitly (logout):
$jwt->revoke($accessToken);
$jwt->revoke($refreshToken);
```

### JWT: configurable clock leeway (Breaking — Phase 5.4)

`verify()` **no longer accepts a `$leeway` parameter**. Clock leeway is now
configured once at construction time (see constructor above). Update any call
sites that passed a leeway argument to `verify()`.

### CSRF enforcement on web routes (Breaking — Phase 5.6)

`CsrfMiddleware` is now part of the default web middleware stack. All
state-changing requests (`POST`, `PUT`, `PATCH`, `DELETE`) on web routes must
include a valid CSRF token.

Use `ionToken()` in Twig templates or include the `_ion_token` hidden field
(default token id `'web'`) in forms. Opt-out for the whole app: set
`app.csrf.enabled = false` in `config/app.php`.

### Login rate-limiting middleware (Phase 5.5)

A new `RateLimitMiddleware` is available. It is **not registered by default** —
apps must wire it up themselves:

```php
// config/app.php
'middleware_aliases' => [
    'throttle' => \Ions\Http\Middleware\RateLimitMiddleware::class,
    // ...
],
```

Then attach to sensitive routes via `Route::middleware(['throttle'])`. On breach
it returns HTTP 429 with a `Retry-After` header. Tune with
`app.ratelimit.max` (default 60) and `app.ratelimit.decay` (seconds, default 60).

### Auth backend pluggable (Phase 5.2)

Set `config('auth.provider')` to `'sentinel'` (default, backward-compatible) or
`'eloquent'` to use the new `EloquentUserProvider`. The selected provider is
bound as `'user_provider'` in the container.

### Static `Guard` / `Guard*` classes deprecated (BC shim retained)

`Ions\Auth\Guard\Guard`, `GuardUser`, `GuardRole`, and `GuardControl` are now
marked `@deprecated`. They continue to work (no behaviour change), but new code
should inject `Ions\Auth\Contracts\UserProvider` instead.

```php
// Before (deprecated):
use Ions\Auth\Guard\Guard;
$result = Guard::login(['email' => $email, 'password' => $password]);

// After (recommended):
/** @var \Ions\Auth\Contracts\UserProvider $provider */
$provider = app('user_provider');
$user = $provider->retrieveByCredentials(['email' => $email, 'password' => $password]);
$ok   = $user && $provider->validateCredentials($user, ['password' => $password]);
```

---

## Phase 4 — Illuminate Upgrade

### Illuminate 9 → 11 / Symfony 7 / Monolog 3 / Pest 3 (Breaking — Phase 4.2 / 4.3 / 4.6)

The framework now requires **Illuminate 11**, **Symfony 7**, **Monolog 3**, and
**Pest 3 / PHPUnit 11**. The upgrade was performed incrementally (9→10→11).
Review the official [Laravel 11 upgrade guide](https://laravel.com/docs/11.x/upgrade)
for Eloquent/container deltas. Generators emit `id()`, use `SoftDeletes`, and
declare `$casts` as an array property.

**Cartalyst Sentinel** was bumped to `^8.0` (the L11-compatible release) and
remains the default auth provider (`auth.provider = sentinel`). No Sentinel-optional
refactor was required — Sentinel 8 supports Illuminate 11 directly.

**`spatie/ignition` and `filp/whoops` removed from `require`** (Phase 4.6). These
were unused at runtime after Phase 3.3 replaced the exception handler. They are no
longer listed in `require` and do not need to be in host `composer.json` either.
Whoops remains available transitively through dev dependencies (Pest / Collision),
but production code must not rely on it being present.

**Symfony 7 routing loaders renamed** — `Annotation*Loader` classes are replaced by
`Attribute*Loader`. This is an internal change and requires no action in host apps
using `Route::get/post/...` or the `#[Route]` attribute. If you extend
`src/Bundles/AttributeRouteControllerLoader.php` directly, update any `extends
AnnotationClassLoader` to `extends AttributeClassLoader`.

**`Ions\Support\Route` now extends `Symfony\Component\Routing\Attribute\Route`**
(the canonical class) instead of the `Annotation\Route` shim. The public
constructor interface is identical; host apps using `#[Route(...)]` are unaffected.

### Query filtering allow-listed by default (Breaking — Phase 4.1)

`QueryBuilder::allowFilters()` now enforces a strict allow-list by default.

**API change:** the old single-string / variadic form
(`allowFilters('col_a', 'col_b')`) has been removed. The parameter **must** be
an array; passing a non-array throws a `TypeError` (fails closed/loud).

```php
// Before (variadic — no longer valid):
$builder->allowFilters('name', 'email');

// After (array-only):
$builder->allowFilters(['name', 'email']);

// Opt out of allow-list enforcement explicitly:
$builder->allowFilters([], true);
// or equivalently:
$builder->allowAllFilters();
```

Any filter column in the request that is not in the allow-list throws
`InvalidFilterQuery`.

### `redbean` database engine removed (Phase 4.5)

The `'redbean'` engine key in `config/database.php` (or `app.database_engine`)
is silently ignored and logs a deprecation notice. Remove it and migrate to the
`'db'` (Eloquent) engine.

---

## Phase 3 — HTTP / Routing / Controllers

### `ApiController` response helpers now return (Breaking — Phase 3.2)

`display()`, `returnStructure()`, and the other `ApiController` response helpers
now **return** a `Response` object instead of echoing and exiting. All call sites
must add `return`:

```php
// Before (implicit exit):
$this->display($data);

// After:
return $this->display($data);
```

Void/echo-based controllers that do not use `ApiController` helpers are
unaffected. The shared response object is still updated for compatibility.

### Smarty removed (Breaking — Phase 3.5)

`smarty/smarty` is no longer a dependency. Twig is the sole view engine. Port
any Smarty templates to Twig. A `'smarty'` entry in `app.templates` is silently
ignored.

### `MRoute` facade removed

Use `Ions\Bundles\Route` directly.

---

## Phase 2 — Container / Middleware

### `app.providers` config key

Setting `app.providers` **replaces** the default provider list. If you set it,
include the framework defaults (`DatabaseProvider`, `FilesystemProvider`,
`ViewProvider`, etc.) or explicitly omit only the ones you want to swap.

### Static service access deprecated

Singleton-based static helpers (where a container binding now exists) carry
`@deprecated` docblocks pointing to the container equivalent. They remain
functional.

---

## Phase 1 — Security Hardening

### JWT secret / `APP_KEY` required (Breaking — Phase 1.1)

See the JWT section above. Short summary: set `APP_KEY` (≥ 32 bytes) in `.env`;
all pre-v2 tokens are invalid on upgrade.

### Upload allow-list enforced (Breaking — Phase 1.2)

`IonUpload` and `IonDisk` now validate file extensions against an allow-list
before storing. Executable and unlisted extensions are rejected.

Configure the allowed set in `config/app.php`:

```php
'uploads' => [
    'allowed' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'zip'],
],
```

The default list covers common safe types; add extensions your app legitimately
needs. The vulnerable `verot/class.upload.php` dependency has been removed.

### Trusted hosts required (Breaking — Phase 1.3)

The old `Host == APP_URL` comparison (spoofable via `X-Forwarded-Host`) is gone.
Set `app.trusted_hosts` to an array of **regex patterns without delimiters**:

```php
// config/app.php
'trusted_hosts' => ['^example\.com$', '^.*\.example\.com$'],
```

Leave the array empty only in local/dev environments where host spoofing is not a
concern. In production an empty list means no host validation.

### Security headers / CSP on every response (Phase 1.3)

Every response now receives the following hardening headers
(`SecurityHeaders::apply()` is called by the kernel):

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `X-XSS-Protection: 0`
- `Content-Security-Policy: default-src 'self'` *(unless already set)*

Customise the CSP via `app.security.csp` in `config/app.php`. Controllers and
middleware may set a stricter route-specific `Content-Security-Policy` header
before the kernel applies its default — the framework will not override a header
that is already present.

---

## Deprecations (still functional)

These remain functional in v2 but will be removed in a future major version:

| Deprecated | Replacement |
|---|---|
| Static `Guard` / `Guard*` | Inject `Ions\Auth\Contracts\UserProvider` |
| `Bundles\QueryBuilder` | `Builders\QueryBuilder` |
| `AppKeys` | `Ions\Security\Jwt` |
