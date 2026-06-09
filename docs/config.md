# Configuration Reference

This is the canonical reference for all framework config keys. Keys live in PHP files under `config/` in the host application. The file name becomes the top-level namespace: `config/app.php` → `app.*`, `config/auth.php` → `auth.*`, etc.

> The older `docs/phase2-config.md` file is preserved for historical context but this document supersedes it as the single canonical config reference.

---

## `app.providers`

**Type:** `array` of FQCN strings implementing `Ions\Container\ServiceProvider`

**Default (when absent):** `Kernel::defaultProviders()`:
`ConfigProvider`, `FilesystemProvider`, `DatabaseProvider`, `AuthProvider`, `MailProvider`, `ViewProvider`

Setting this key **replaces** the default list entirely. Include any framework providers you still need.

```php
'providers' => [
    \Ions\Providers\FilesystemProvider::class,
    \Ions\Providers\DatabaseProvider::class,
    \Ions\Providers\AuthProvider::class,
    \App\Providers\AppServiceProvider::class,
],
```

---

## `app.middleware`

**Type:** `array<string, MiddlewareInterface[]>` — per-group stacks, fully-built instances.

**Default (when absent):** `Kernel::defaultMiddleware()` — see [middleware.md](middleware.md).

When set, the array must contain fully instantiated `MiddlewareInterface` objects; the kernel uses it as-is.

---

## `app.middleware_aliases`

**Type:** `array<string, class-string>`

Maps short alias names to middleware FQCNs for use in `Route::middleware([...])`.

```php
'middleware_aliases' => [
    'throttle' => \Ions\Http\Middleware\RateLimitMiddleware::class,
    'auth'     => \Ions\Http\Middleware\AuthMiddleware::class,
],
```

---

## `app.cors`

**Type:** `array`  **Default:** `[]`

Passed to `CorsMiddleware`. Recognised keys:

| Key | Description |
|---|---|
| `origins` | Allowed origin pattern(s) |
| `methods` | Allowed HTTP methods |
| `headers` | Allowed request headers |
| `max_age` | Preflight cache duration (seconds) |

---

## `app.trusted_hosts`

**Type:** `array` of regex strings (no delimiters)  **Default:** `[]`

Patterns passed to Symfony's `Request::setTrustedHosts()`. Requests from non-matching hosts are rejected by `TrustedHostMiddleware`.

```php
'trusted_hosts' => ['^myapp\.example\.com$', '^localhost$'],
```

An empty array disables host validation (safe for local dev only).

---

## `app.jwt.ttl`

**Type:** `int` (seconds)  **Default:** `3600` (1 hour)

Access token lifetime. Used by `Kernel::buildJwt()` when constructing `Ions\Security\Jwt`.

---

## `app.jwt.leeway`

**Type:** `int` (seconds)  **Default:** `0`

Clock-skew tolerance for `StrictValidAt` when verifying `iat`, `nbf`, and `exp` claims. Compensates for NTP drift between services. Recommended range: 0–30 s.

---

## `app.jwt.refresh_ttl`

**Type:** `int` (seconds)  **Default:** `1209600` (14 days)

Refresh token lifetime issued by `Jwt::issueRefresh()`.

```php
'jwt' => [
    'ttl'         => 3600,
    'leeway'      => 5,
    'refresh_ttl' => 1209600,
],
```

---

## `app.csrf.enabled`

**Type:** `bool`  **Default:** `true`

When `true`, `CsrfMiddleware` is included in the default web stack. State-changing requests (`POST`, `PUT`, `PATCH`, `DELETE`) must include `_ion_token` (body field) or `X-CSRF-TOKEN` (header). Missing/invalid token → HTTP 419.

```php
'csrf' => ['enabled' => false],  // disable for API-only apps
```

---

## `app.security.csp`

**Type:** `string`  **Default:** `"default-src 'self'"`

Value of the `Content-Security-Policy` header applied by `SecurityHeaders::apply()`. Only set when the header is not already present on the response (controllers may set a stricter route-specific policy).

```php
'security' => [
    'csp' => "default-src 'self'; script-src 'self' https://cdn.example.com",
],
```

---

## `app.uploads.allowed`

**Type:** `string[]`  **Default:** common safe types (images, PDF, zip)

Extension allow-list enforced by `Ions\Security\UploadValidator` (used by `IonUpload` and `IonDisk`). Executable extensions (PHP, scripts, binaries) are always rejected regardless of this list.

```php
'uploads' => [
    'allowed' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'zip'],
],
```

---

## `app.ratelimit.max`

**Type:** `int`  **Default:** `60`

Maximum requests per window for `RateLimitMiddleware`.

## `app.ratelimit.decay`

**Type:** `int` (seconds)  **Default:** `60`

Window length for rate limiting. After `max` hits within `decay` seconds, subsequent requests from the same IP to the same path receive HTTP 429 with a `Retry-After` header.

```php
'ratelimit' => [
    'max'   => 5,
    'decay' => 60,
],
```

---

## `app.preloads`

**Type:** `string[]`  **Default:** `[]`

Paths relative to `src/` that are `include_once`'d during boot (e.g. global helpers or aliases).

---

## Auth config (`config/auth.php`)

### `auth.provider`

**Type:** `string`  **Default:** `'sentinel'`

| Value | Provider |
|---|---|
| `'sentinel'` | `Ions\Auth\Providers\SentinelUserProvider` |
| `'eloquent'` | `Ions\Auth\Providers\EloquentUserProvider` |
| FQCN | Resolved via the container |

### `auth.table`

**Type:** `string`  **Default:** `'users'`

Database table queried by `EloquentUserProvider`.

### `auth.identifier`

**Type:** `string`  **Default:** `'email'`

Column used by `EloquentUserProvider::retrieveByCredentials()` to look up a user.

### `auth.password`

**Type:** `string`  **Default:** `'password'`

Column storing the bcrypt/argon2 hash; verified via `password_verify()`.

### `auth.id`

**Type:** `string`  **Default:** `'id'`

Primary-key column; used by `EloquentUserProvider::retrieveById()`.

```php
// config/auth.php
return [
    'provider'   => 'eloquent',
    'table'      => 'users',
    'identifier' => 'email',
    'password'   => 'password',
    'id'         => 'id',
];
```

---

## Twig view config

### `app.twig.source`

**Type:** `string`  **Default:** `Path::views('default')`

Template source directory.

### `app.twig.cache`

**Type:** `string`  **Default:** `Path::cache('twig')`

Compiled template cache directory.

### `app.twig.paths`

**Type:** `string[]`  **Default:** `[]`

Additional named namespace paths added to the Twig `FilesystemLoader`.
