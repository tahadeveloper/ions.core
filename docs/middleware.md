# Middleware

## Contract

Every middleware must implement `Ions\Http\Middleware\MiddlewareInterface`:

```php
namespace Ions\Http\Middleware;

interface MiddlewareInterface
{
    /** @param callable(Request):Response $next */
    public function handle(Request $request, callable $next): Response;
}
```

Call `$next($request)` to continue the chain. Return a `Response` directly to short-circuit it.

## Pipeline

`Ions\Http\Middleware\Pipeline` wraps a list of `MiddlewareInterface` instances around a terminal callable using `array_reduce`:

```php
$response = (new Pipeline($middlewareArray, $terminal))->handle($request);
```

Middleware is applied in order: the first item in the array is outermost (runs first on the way in, last on the way out). Per-route middleware is appended to the group stack, so it runs closest to the controller.

## Default stacks

Built by `Kernel::defaultMiddleware()` and used when `app.middleware` is not set in config.

### Web group (`routes/web.php`)

1. `TrustedHostMiddleware` — rejects requests from hosts not matching `app.trusted_hosts` patterns.
2. `SecurityHeadersMiddleware` — sets hardening response headers via `SecurityHeaders::apply()`.
3. `CorsMiddleware` — applies CORS headers using `app.cors` config.
4. `CsrfMiddleware` — enforces `_ion_token` / `X-CSRF-TOKEN` on `POST/PUT/PATCH/DELETE` (added when `app.csrf.enabled` is `true` and the `csrf` container binding exists).

### API group (`routes/api.php`)

1. `TrustedHostMiddleware`
2. `SecurityHeadersMiddleware`
3. `CorsMiddleware`
4. `AuthMiddleware` — validates Bearer JWT and resolves the user (see [auth.md](auth.md)).

## Overriding the default stacks

Set `app.middleware` in `config/app.php` to a fully-built array of `MiddlewareInterface` instances:

```php
// config/app.php
'middleware' => [
    'web' => [
        new \Ions\Http\Middleware\TrustedHostMiddleware(config('app.trusted_hosts', [])),
        new \Ions\Http\Middleware\SecurityHeadersMiddleware(),
        // add or remove middleware here
    ],
    'api' => [
        // ...
    ],
],
```

When this key is present the framework uses it as-is; `Kernel::defaultMiddleware()` is not called.

## Writing a middleware

Use the generator:

```bash
php artisan make:middleware CheckSubscription
# or
vendor/bin/ions make:middleware CheckSubscription
```

This produces a stub in `src/Http/Middleware/CheckSubscriptionMiddleware.php`. Fill in the logic:

```php
<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Ions\Http\Middleware\MiddlewareInterface;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

final class CheckSubscriptionMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (!$request->attributes->get('auth_user')?->isSubscribed()) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(
                ['status' => 'error', 'message' => 'Subscription required'],
                402
            );
        }
        return $next($request);
    }
}
```

## Per-route middleware

Attach middleware to a route by alias or FQCN:

```php
// config/app.php
'middleware_aliases' => [
    'subscribed' => \App\Http\Middleware\CheckSubscriptionMiddleware::class,
    'throttle'   => \Ions\Http\Middleware\RateLimitMiddleware::class,
],
```

```php
// routes/web.php
Route::get('/premium', 'ContentController@premium')
    ->middleware(['subscribed']);
```

The Kernel resolves aliases from `app.middleware_aliases`, then instantiates the class via the container. An unknown alias or unresolvable class **fails the request** (500): per-route middleware is never silently dropped, so a broken alias can't leave a guarded route serving traffic unprotected. In debug mode the error names the middleware; in production the body is generic and the detail goes to the log.
