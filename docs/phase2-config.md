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
