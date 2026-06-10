# Session

The session subsystem wraps a Symfony `Session` behind a config-driven manager,
binds it in the container, exposes a `session()` helper, and starts/persists it
through middleware in the web stack. CSRF tokens live in this same session, so
`csrfToken()`/`ionToken()` and `CsrfMiddleware` share a single source of truth.

## Components

| Class | Role |
| ----- | ---- |
| `Ions\Session\SessionManager` | The session abstraction over a Symfony `Session`. Bound in the container as `session` by `SessionProvider`. |
| `Ions\Providers\SessionProvider` | Binds `session` (and a `request_stack`) from `config('session')`. |
| `Ions\Http\Middleware\StartSessionMiddleware` | Web-stack middleware that starts the session at the front of the request (before CSRF) and saves it on the way out. |

## Configuration

See [config.md](config.md#session-config-configsessionphp). In short:

```php
// config/session.php
return [
    'driver'          => 'native',  // 'native' | 'array' | 'mock'
    'name'            => 'ion_session',
    'lifetime'        => 0,
    'cookie_secure'   => false,
    'cookie_httponly' => true,
    'cookie_samesite' => 'lax',
];
```

- `native` — real PHP session (`NativeSessionStorage`); use in production/web.
- `array` / `mock` — in-memory (`MockArraySessionStorage`); use in tests and CLI,
  where a native session would emit "headers already sent" warnings.

## The `session()` helper

Mirrors the `config()` helper overloads:

```php
session();                  // the SessionManager instance
session('key');             // get a value
session('key', 'default');  // get with a default
session(['k' => 'v']);      // put one or more values (starts the session)
```

## Manager API

```php
$s = session();             // SessionManager
$s->start();                // start the underlying session
$s->isStarted();            // bool
$s->get('key', $default);
$s->put('key', $value);
$s->has('key');
$s->forget('key');
$s->all();                  // array
$s->flush();                // clear all
$s->flash('key', $value);   // write a one-request flash value
$s->getFlash('key', []);    // read (and consume) a flash value
$s->regenerate($destroy = false);
$s->token();                // CSRF token (shared with CsrfMiddleware)
$s->getId();
$s->getName();
$s->save();                 // persist
$s->getSession();           // the underlying Symfony Session
```

## Lifecycle integration

`StartSessionMiddleware` is added to the default **web** stack *before*
`CsrfMiddleware` (the kernel only adds it when a `session` binding exists). It
starts the session at the front of the request so CSRF and downstream code share
it, and saves it as the response unwinds. API requests do not start a session by
default.

## CSRF

CSRF tokens are stored in the bound session via a `SessionTokenStorage`, so
`csrfToken()` / `ionToken()` (Twig helpers) and the `CsrfMiddleware` check
operate on the same store. See [auth.md](auth.md) and
[config.md](config.md#appcsrfenabled) for the CSRF surface.
