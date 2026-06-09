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

## AuthMiddleware

`Ions\Http\Middleware\AuthMiddleware` is included in the default `api` middleware stack. It:

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
