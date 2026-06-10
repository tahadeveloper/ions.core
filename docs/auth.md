# Authentication

## UserProvider and Authenticatable

The authentication system is built around two contracts:

```php
// Ions\Auth\Contracts\UserProvider
interface UserProvider
{
    public function retrieveById(string|int $id): ?Authenticatable;
    public function retrieveByCredentials(array $credentials): ?Authenticatable;
    public function validateCredentials(Authenticatable $user, array $credentials): bool;
}

// Ions\Auth\Contracts\Authenticatable
interface Authenticatable
{
    public function getAuthIdentifier(): string|int;
    public function getAuthIdentifierName(): string;
}
```

`AuthProvider` binds the selected provider as the `user_provider` singleton. Select via `auth.provider` in `config/auth.php`:

| Value | Class |
|---|---|
| `'sentinel'` (default) | `Ions\Auth\Providers\SentinelUserProvider` |
| `'eloquent'` | `Ions\Auth\Providers\EloquentUserProvider` |
| Any FQCN | Resolved via the container |

`EloquentUserProvider` reads config keys `auth.table` (default `users`), `auth.identifier` (default `email`), `auth.password` (default `password`), and `auth.id` (default `id`). See [config.md](config.md) for details.

## JWT (`Ions\Security\Jwt`)

### Issuing tokens

```php
/** @var \Ions\Security\Jwt $jwt */
$jwt = app('jwt');

// Short-lived access token (TTL: app.jwt.ttl, default 3 600 s)
$accessToken = $jwt->issue(string $userId, array $claims = []): string;

// Long-lived refresh token (TTL: app.jwt.refresh_ttl, default 1 209 600 s / 14 days)
$refreshToken = $jwt->issueRefresh(string $userId): string;
```

Custom `$claims` passed to `issue()` are merged into the token; reserved claims (`typ`, `jti`, `iss`, `aud`, `sub`, `iat`, `nbf`, `exp`) are silently ignored to prevent injection.

### Verifying access tokens

```php
$claims = $jwt->verify(string $token): \Ions\Security\Claims;
// $claims->userId — the 'sub' value
// $claims->all   — all claims as an array
```

`verify()` rejects refresh tokens (`typ !== 'access'`), expired tokens, invalid signatures, and revoked `jti` values. Throws `Ions\Security\TokenException` on any failure.

### Refreshing tokens

```php
// Exchange a valid refresh token for a new access token.
// The presented refresh token is immediately revoked (rotation).
$newAccessToken = $jwt->refresh(string $refreshToken): string;
```

### Revoking tokens

```php
$jwt->revoke(string $token): void;
```

Works for both access and refresh tokens. Adds the token's `jti` to the `RevocationStore`. No-op when no revocation store is configured.

### Clock leeway

Clock skew tolerance is set at construction time via `app.jwt.leeway` (seconds, default 0). `Kernel::buildJwt()` reads this from config automatically. A non-zero value tolerates NTP drift between the issuer and verifier.

### Revocation store

`AuthProvider` registers a file-cache-backed `CacheRevocationStore` at `var/cache/revocations`. To use a distributed store (e.g. Redis), bind your own `RevocationStore` implementation as `'revocation_store'` before `AuthProvider` registers:

```php
Kernel::app()->singleton('revocation_store', fn () => new MyRedisRevocationStore());
```

## HTTP auth surface (`Ions\Auth\Http\AuthController`)

The framework ships a ready-made controller exposing the login / refresh / logout /
password-reset endpoints. It depends only on the container-bound `jwt` and
`user_provider`, and every action returns a `Json` response.

Issued **access tokens are bound to the authenticated user's id**
(`Jwt::issue($user->getAuthIdentifier())`), so `AuthMiddleware` resolves the real
user on protected routes — never an application id. (The legacy
`AppKeys::createJWT()` defaults its subject to the app id for BC; always pass the
user id, or use this controller / `Jwt` directly.)

### Registering the routes

Reference the actions from closures in `routes/api.php` (closures avoid the
api-namespace controller-prefixing applied to bare controller strings):

```php
use Ions\Auth\Http\AuthController;
use Ions\Bundles\Route;
use Ions\Support\Request;

Route::post('/api/auth/login', fn (Request $r) => (new AuthController())->login($r))
    ->middleware(['throttle']); // rate-limited
Route::post('/api/auth/refresh', fn (Request $r) => (new AuthController())->refresh($r));
Route::post('/api/auth/logout', fn (Request $r) => (new AuthController())->logout($r));
Route::post('/api/auth/password/forgot', fn (Request $r) => (new AuthController())->forgotPassword($r));
Route::post('/api/auth/password/reset', fn (Request $r) => (new AuthController())->resetPassword($r));
```

Because these paths live under `/api`, the default `api` stack would otherwise apply
`AuthMiddleware`. List the auth paths in `app.auth.public_paths` so they bypass
authentication (they establish a session rather than depend on one):

```php
// config/app.php
'auth' => [
    'public_paths' => [
        '/api/auth/login', '/api/auth/refresh', '/api/auth/logout',
        '/api/auth/password/forgot', '/api/auth/password/reset',
    ],
],
'middleware_aliases' => [
    'throttle' => \Ions\Http\Middleware\RateLimitMiddleware::class,
],
```

`public_paths` entries are matched as path **prefixes**; only the listed prefixes
bypass token verification — the 401 semantics for every other route are unchanged.

### Endpoints

| Method & path | Body | Success (200) | Failure |
|---|---|---|---|
| `POST /api/auth/login` | `{ "email", "password" }` | `{ status:"success", data:{ access_token, refresh_token, token_type:"Bearer" } }` | `401` invalid credentials |
| `POST /api/auth/refresh` | `{ "refresh_token" }` *(or Bearer)* | `{ data:{ access_token, token_type:"Bearer" } }` | `401` invalid/expired/revoked |
| `POST /api/auth/logout` | — *(Bearer access token)* | `{ data:{ message:"logged out" } }` | — |
| `POST /api/auth/password/forgot` | `{ "email" }` | `{ data:{ message } }` *(generic — no user enumeration)* | `501` provider lacks reset support |
| `POST /api/auth/password/reset` | `{ "email", "code", "password" }` | `{ data:{ message:"password reset" } }` | `422` invalid code / missing fields; `501` unsupported |

`login` runs `UserProvider::retrieveByCredentials()` + `validateCredentials()`,
then issues an access **and** refresh token. `refresh` calls `Jwt::refresh()`,
which rotates the refresh token (the presented one is immediately revoked) and
returns a fresh access token. `logout` calls `Jwt::revoke()` on the access token,
adding its `jti` to the `RevocationStore`.

### Password reset

The reset endpoints work when the bound `UserProvider` implements
`Ions\Auth\Contracts\SupportsPasswordReset` (`createResetCode()` /
`resetPassword()`). The default `SentinelUserProvider` implements it via Sentinel's
reminder repository: `forgot` issues a reminder code (delivered out-of-band, never
returned in the response), and `reset` completes the reminder with that code and the
new password. Providers without this capability return `501`; the **Eloquent path is
provider-dependent** — `EloquentUserProvider` does not ship a reset-token table, so
apps using it must supply a provider that implements `SupportsPasswordReset`.

## AuthMiddleware

`Ions\Http\Middleware\AuthMiddleware` is included in the default `api` middleware stack. It:

0. Skips authentication entirely when the request path matches an `app.auth.public_paths` prefix (e.g. the login/refresh/reset endpoints).
1. Reads the `Authorization` header and expects a `Bearer <token>` value.
2. Calls `Jwt::verify()` on the token.
3. Sets `auth_user_id` on `$request->attributes` (always, when valid).
4. If a `UserProvider` is bound, calls `retrieveById()` and sets `auth_user` on `$request->attributes`. Returns 401 if the user is not found.
5. Returns a 401 JSON response (`Not authorized!`) on any failure.

Access the authenticated user in a controller:

```php
$userId = $request->attributes->get('auth_user_id');
$user   = $request->attributes->get('auth_user'); // Authenticatable|null
```

## Rate limiting

`Ions\Http\Middleware\RateLimitMiddleware` throttles requests by IP + path. It is not in the default stacks — attach it per-route:

```php
// config/app.php
'middleware_aliases' => [
    'throttle' => \Ions\Http\Middleware\RateLimitMiddleware::class,
],
```

```php
Route::post('/login', 'AuthController@login')->middleware(['throttle']);
```

When the limit is exceeded it returns HTTP 429 with a `Retry-After` header. Configured via `app.ratelimit.max` (default 60 requests) and `app.ratelimit.decay` (default 60 seconds). See [config.md](config.md) for details.

## CSRF

`CsrfMiddleware` is in the default web stack when `app.csrf.enabled` is `true` (the default). It protects `POST`, `PUT`, `PATCH`, and `DELETE` requests.

Include the token in Twig templates:

```twig
<form method="POST">
    {{ ionToken('web') }}
    ...
</form>
```

Or send it as a header for AJAX:

```js
headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
```

The raw token string is available via `csrfToken(string $tokenId)`. The CSRF manager is bound in the container as `'csrf'`; swap it in tests with `Kernel::app()->instance('csrf', $mock)`.

To disable CSRF for an entire app (e.g. API-only):

```php
// config/app.php
'csrf' => ['enabled' => false],
```
