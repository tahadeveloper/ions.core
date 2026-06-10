# Testing host applications

`Ions\Testing\TestCase` is the framework's host-facing test kit: subclass it
in your application, point it at your app root, and drive the full HTTP stack
in-process — real kernel boot, real routing, real middleware (auth, CSRF,
security headers), no web server. Every request returns an
`Ions\Testing\TestResponse` with fluent, chainable assertions.

The framework's own suite uses the exact same machinery
(`tests/Feature/Testing/TestCaseTest.php` is a working reference).

## Subclassing `TestCase`

Set the protected `$basePath` property to your application root — the
directory containing `config/`, `routes/` and `.env` (in the
[skeleton layout](skeleton.md) that is the project root):

```php
<?php

declare(strict_types=1);

namespace Tests;

abstract class AppTestCase extends \Ions\Testing\TestCase
{
    // tests/ lives directly under the app root in the skeleton layout
    protected string $basePath = __DIR__ . '/..';
}
```

Override `protected function basePath(): string` instead when the path needs
to be computed. An unset or non-existent path throws a `RuntimeException`
explaining what to set.

Lifecycle — handled for you, per test:

- `setUp()` snapshots `$_ENV`/`$_SERVER` (booting loads your `.env` into
  them), then `Kernel::resetForTesting()` + `Kernel::boot($basePath)`.
- `tearDown()` resets the kernel again and restores the env superglobals, so
  no framework or env state ever leaks between tests.

> Tip: like the framework's own suite, point your test `.env` at in-memory
> drivers (`SESSION_DRIVER=array`, SQLite `:memory:`, array cache/queue) so
> tests stay fast and hermetic.

## Making requests

```php
$this->get('/users', headers: ['Accept-Language' => 'de']);
$this->post('/users', ['name' => 'Ion']);          // form-encoded parameters
$this->put('/users/7', ['name' => 'Ion']);
$this->patch('/users/7', ['name' => 'Ion']);
$this->delete('/users/7');

// JSON body + Content-Type/Accept: application/json
$this->json('POST', '/api/users', ['name' => 'Ion', 'tags' => ['a', 'b']]);

// Low-level escape hatch (raw body, custom verb):
$this->call('PURGE', '/cache/users', parameters: [], headers: [], content: null);
```

Each helper builds an `Ions\Support\Request`, passes it through
`Kernel::handle()` (the same pipeline production traffic takes) and returns a
`TestResponse`.

## Authentication: `actingAs()`

`actingAs()` issues a **real JWT** through the kernel's configured signer and
sends it as `Authorization: Bearer …` on every subsequent request — exactly
the token `AuthMiddleware` verifies. It accepts an
`Ions\Auth\Contracts\Authenticatable` or a plain user id, plus optional extra
claims:

```php
$this->actingAs($user)->get('/api/profile')->assertOk();
$this->actingAs('user-99', ['scope' => 'admin'])->get('/api/admin')->assertOk();
```

**Requires `APP_KEY`** in the application's `.env`: a random secret of at
least 32 bytes (e.g. a 64-character hex string —
`php -r "echo bin2hex(random_bytes(32));"`). Without it, JWT signing is
disabled and `actingAs()` throws a `RuntimeException` telling you what to set.

Default headers (manual control):

```php
$this->withToken($jwtString);                  // Authorization: Bearer …
$this->withHeaders(['X-Tenant' => 'acme']);    // merged into every request
$this->flushHeaders();                         // reset (also happens per test)
```

Stored defaults ride along on every subsequent request; per-call headers win
on conflict.

## `TestResponse` assertions

All assertions delegate to PHPUnit (failures report normally) and return
`$this` for chaining.

| Assertion | Verifies |
|---|---|
| `assertStatus(int $code)` | Exact status code |
| `assertOk()` / `assertCreated()` / `assertNoContent()` | 200 / 201 / 204 (204 also asserts an empty body) |
| `assertRedirect(?string $to = null)` | Redirect response; optionally the exact `Location` |
| `assertSee(string $value)` | Raw body contains the string |
| `assertJson(array $subset)` | Decoded body contains the array as a **recursive subset**: every given key must exist with a matching value, nested arrays recurse, lists match by index, extra response keys are ignored |
| `assertJsonPath(string $dotPath, mixed $expected)` | Value at a dot path (`data.user.id`, `tags.1`) is **identical** (`assertSame`) to `$expected` |
| `assertHeader(string $name, ?string $value = null)` | Header presence, optionally its exact value |

Accessors: `status()`, `content()`, `headers()`,
`json(?string $key = null)` (full decoded body, or dot-path access; `null`
when absent or not JSON), and the public `baseResponse` property — the
underlying Symfony response, as an escape hatch.

## Complete example (skeleton layout)

```php
<?php

declare(strict_types=1);

namespace Tests;

final class ApiTest extends AppTestCase   // see "Subclassing" above
{
    public function test_ping_is_public(): void
    {
        $this->get('/api/ping')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson(['status' => 'success'])
            ->assertJsonPath('data.message', 'pong');
    }

    public function test_profile_requires_a_token(): void
    {
        $this->get('/api/profile')->assertStatus(401);
        $this->withToken('garbage')->get('/api/profile')->assertStatus(401);
    }

    public function test_an_authenticated_user_can_update_their_profile(): void
    {
        $this->actingAs('user-99')
            ->json('PUT', '/api/profile', ['name' => 'Ion'])
            ->assertOk()
            ->assertJson(['data' => ['name' => 'Ion']]);
    }
}
```

Run with Pest or PHPUnit — `Ions\Testing\TestCase` is a plain
`PHPUnit\Framework\TestCase` subclass, so both work unchanged:

```bash
vendor/bin/pest          # or: vendor/bin/phpunit
```
