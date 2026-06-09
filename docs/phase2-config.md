# Phase 2 Config Keys Reference

This document describes the `app.*` configuration keys introduced in Phase 2 of the ions.core modernization. All keys live in `config/app.php` (or whatever config file is loaded from `Path::config()`).

---

## `app.providers`

**Type:** `array` of fully-qualified class-strings implementing `Ions\Container\ServiceProvider`

**Default (when key is absent):** `Kernel::defaultProviders()` — `[FilesystemProvider::class, DatabaseProvider::class]`

```php
'providers' => [
    \Ions\Providers\FilesystemProvider::class,
    \Ions\Providers\DatabaseProvider::class,
],
```

When this key is **set**, it **replaces** the defaults entirely. Apps that still need filesystem or database wiring must include those providers explicitly.

Bootstrap order: two-pass — all `register()` methods run first (so every binding is available), then all `boot()` methods run.

---

## `app.middleware`

**Type:** `array<string, list<MiddlewareInterface>>` — per-group middleware stacks.

**Default (when key is absent):** `Kernel::defaultMiddleware()`:

```php
'middleware' => [
    'web' => [
        new TrustedHostMiddleware(config('app.trusted_hosts', [])),
        new SecurityHeadersMiddleware(),
        new CorsMiddleware(config('app.cors', [])),
    ],
    'api' => [
        new TrustedHostMiddleware(config('app.trusted_hosts', [])),
        new SecurityHeadersMiddleware(),
        new CorsMiddleware(config('app.cors', [])),
        new AuthMiddleware($jwt),   // enforces Bearer JWT auth
    ],
],
```

- **`web` group** — requests whose first path segment is **not** `api`. No authentication is enforced by default.
- **`api` group** — requests whose first path segment is `api`. `AuthMiddleware` runs last and rejects requests without a valid Bearer JWT token with a `401 Unauthorized` JSON response.

When `app.middleware` is set via config, the value must be a fully-built array of `MiddlewareInterface` instances (not class-strings). The kernel uses it as-is without any further construction.

---

## `app.cors`

**Type:** `array`

**Default:** `[]` (permissive — CorsMiddleware applies no restrictions)

Passed directly to `CorsMiddleware`. Recognized keys:

| Key | Description |
|-----|-------------|
| `origins` | Allowed origin pattern(s). |
| `methods` | Allowed HTTP methods. |
| `headers` | Allowed request headers. |
| `max_age` | Preflight cache duration in seconds. |

---

## `app.jwt.ttl`

**Type:** `int` (seconds)

**Default:** `3600` (1 hour)

Token lifetime used by `Kernel::buildJwt()` when constructing the `Ions\Security\Jwt` instance passed to `AuthMiddleware`. The signing secret is read from the `APP_KEY` environment variable (minimum 32 bytes); if the key is absent or too short, JWT signing is disabled and all API requests will receive `401`.

---

## `app.jwt.leeway`

**Type:** `int` (seconds)

**Default:** `0` (strict — no tolerance)

Clock-skew leeway passed to `StrictValidAt` when verifying JWT timestamps (`iat`, `nbf`, `exp`). A non-zero value allows tokens whose expiry (or nbf/iat) is off by at most this many seconds relative to the verifier's clock to still be accepted. This compensates for NTP drift between the issuer node and the verifier node.

```php
// config/app.php
'jwt' => [
    'ttl'    => 3600,
    'leeway' => 5,   // tolerate up to 5 seconds of clock skew
],
```

- `0` (default): strict validation — a token expired even 1 second ago is rejected.
- Recommended range: `0`–`30` seconds. Values above 60 s significantly weaken expiry enforcement.

**D5-A status:** implemented — `clockLeewaySeconds` is the 5th constructor parameter of `Ions\Security\Jwt`.

---

## `app.jwt.refresh_ttl`

**Type:** `int` (seconds)

**Default:** `1209600` (14 days)

Lifetime of refresh tokens issued by `Jwt::issueRefresh()`. After this period the refresh token expires and the user must re-authenticate to obtain a new one.

```php
// config/app.php
'jwt' => [
    'ttl'         => 3600,      // access token lifetime (1 hour)
    'refresh_ttl' => 1209600,   // refresh token lifetime (14 days)
    'leeway'      => 0,
],
```

**D5-B status:** implemented — `refreshTtlSeconds` is the 7th constructor parameter of `Ions\Security\Jwt`.

---

## JWT Token Types and Revocation (D5-B)

### Token types

Access and refresh tokens are distinguished by a `typ` claim:

| Token | `typ` claim | Issued by | Accepted by |
|-------|-------------|-----------|-------------|
| Access token | `'access'` | `Jwt::issue()` | `Jwt::verify()` |
| Refresh token | `'refresh'` | `Jwt::issueRefresh()` | `Jwt::refresh()` |

`Jwt::verify()` rejects tokens with `typ !== 'access'`, so refresh tokens cannot be used as access tokens and vice-versa.

### Revocation (jti deny-list)

`Jwt::revoke(string $token)` adds the token's `jti` (JWT ID) to the configured `RevocationStore`. After revocation, `Jwt::verify()` throws `TokenException` for that token even if it has not yet expired.

**Refresh token rotation:** `Jwt::refresh()` automatically revokes the presented refresh token before issuing a new access token. Reusing a rotated refresh token throws `TokenException`.

### RevocationStore contract

```php
interface RevocationStore {
    public function revoke(string $jti, int $ttlSeconds): void;
    public function isRevoked(string $jti): bool;
}
```

### Default store: `ArrayRevocationStore`

The default implementation is in-memory (`ArrayRevocationStore`). Revocations only persist within the lifetime of the PHP process (a single request). This is sufficient for refresh-token rotation within a request but **does not** provide cross-request logout persistence.

### Persistent revocation (cross-request logout)

To enable revocations that survive across requests (e.g. logout invalidating a token), bind a persistent `RevocationStore` implementation in the container **before** `AuthProvider` registers:

```php
// In your application service provider or bootstrap:
Kernel::app()->singleton('revocation_store', function () {
    return new \App\Security\CacheRevocationStore(cache());
});
```

A `CacheRevocationStore` backed by Illuminate Cache (or any PSR-16 compatible cache) can be added as a follow-up. The interface is stable — only the store implementation needs to change.

### BC guarantee

When `Jwt` is constructed without a `RevocationStore` (`$revocations = null`), `verify()` behaves exactly as before — no revocation check is performed and no revocation-store dependency is required.

---

## `app.trusted_hosts`

**Type:** `array` of regex patterns (strings WITHOUT delimiters)

**Default:** `[]` (no host restriction)

Patterns are passed to `TrustedHostMiddleware`, which wraps them with Symfony's `Request::setTrustedHosts()`. Example:

```php
'trusted_hosts' => [
    '^myapp\.example\.com$',
    '^localhost$',
],
```

Requests from hosts not matching any pattern will be rejected by the middleware.

---

## `app.csrf.enabled`

**Type:** `bool`

**Default:** `true`

Controls whether `CsrfMiddleware` is included in the **web** middleware stack. When `true` (the default), all state-changing requests (`POST`, `PUT`, `PATCH`, `DELETE`) on web routes must include a valid CSRF token either as a `_ion_token` field in the request body or an `X-CSRF-TOKEN` header. Missing or invalid tokens return a `419` response.

```php
// config/app.php
'csrf' => [
    'enabled' => true,   // set to false to disable CSRF enforcement (e.g. in API-only apps or during testing)
],
```

Token generation in views:
- `ionToken()` — renders a hidden `<input>` field with the token.
- `csrfToken()` — returns the raw token string for use in headers or custom fields.

The CSRF token manager is bound in the container as `'csrf'` (a `CsrfTokenManagerInterface` singleton) so it can be swapped for a test double:

```php
Kernel::app()->instance('csrf', $myTestManager);
```

> **v2 Upgrade Guide:** CSRF is now **enforced by default** on all state-changing web routes. Include `ionToken()` or a `_ion_token` field (or `X-CSRF-TOKEN` header) in all web forms/requests. To disable: set `app.csrf.enabled = false` in config.

---

## Auth config keys (Phase 5)

These keys configure the authentication subsystem introduced in Phase 5.

### `auth.provider`

**Type:** `string`

**Default:** `'sentinel'`

Selects the `UserProvider` implementation that `AuthProvider` binds as the `user_provider` singleton.

| Value | Resolved class |
|-------|---------------|
| `'sentinel'` *(default)* | `Ions\Auth\Providers\SentinelUserProvider` |
| `'eloquent'` | `Ions\Auth\Providers\EloquentUserProvider` |
| Any FQCN (e.g. `App\Auth\CustomProvider`) | Resolved via the container (`$container->make($class)`) |
| Unknown string | Falls back to `SentinelUserProvider` |

```php
// config/auth.php
return [
    'provider' => 'eloquent',   // or 'sentinel', or a FQCN
];
```

---

### `auth.table`

**Type:** `string`

**Default:** `'users'`

The database table queried by `EloquentUserProvider`.

---

### `auth.identifier`

**Type:** `string`

**Default:** `'email'`

The column used by `EloquentUserProvider::retrieveByCredentials()` to look up a user (the "login name" column). Credentials arrays must include this key.

---

### `auth.password`

**Type:** `string`

**Default:** `'password'`

The column that stores the bcrypt/argon2 password hash in the users table. Used by `EloquentUserProvider::validateCredentials()` via `password_verify()`.

---

### `auth.id`

**Type:** `string`

**Default:** `'id'`

The primary-key column of the users table. Used by `EloquentUserProvider::retrieveById()` and exposed via `Authenticatable::getAuthIdentifierName()`.
