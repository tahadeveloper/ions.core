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

**Type:** `array`  **Default:** `[]` — **deny-by-default since 4.1 (D8-1)**

Passed to `CorsMiddleware`. With no `origins` configured, no CORS headers are
emitted at all (cross-origin browser requests are denied; preflights get a
plain 204). Recognised keys:

| Key | Description |
|---|---|
| `origins` | Allowed origins (exact strings), or `['*']` for a public wildcard. Default `[]` = deny |
| `methods` | Allowed HTTP methods |
| `headers` | Allowed request headers |
| `max_age` | Preflight cache duration (seconds) |
| `credentials` | Emit `Access-Control-Allow-Credentials: true`. Only honoured when explicitly `true` **and** `origins !== ['*']` (the Fetch spec forbids credentials with a wildcard) |

```php
'cors' => [
    'origins' => ['https://app.example.com'],
    'credentials' => true,
],
```

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

## `app.security.hsts`

**Type:** `string|false`  **Default:** `"max-age=31536000; includeSubDomains"`

Value of the `Strict-Transport-Security` header. Only emitted when the handled
request is HTTPS (`$request->isSecure()`), and only when the response does not
already carry the header. Set to `false` to disable.

---

## `app.security.permissions_policy`

**Type:** `string|false`  **Default:** `"camera=(), geolocation=(), microphone=()"`

Value of the `Permissions-Policy` header applied by `SecurityHeaders::apply()`.
Only set when the header is not already present on the response. Set to
`false` to disable.

```php
'security' => [
    'hsts' => 'max-age=63072000; includeSubDomains; preload',
    'permissions_policy' => 'camera=(self), geolocation=(), microphone=()',
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

---

## Filesystem config (`config/filesystem.php`)

The config-driven filesystem is backed by [Flysystem](https://flysystem.thephpleague.com/).
`Ions\Filesystem\FilesystemManager` resolves named disks from `filesystem.disks`,
each entry being a driver name plus its options. The `Storage` helper
(`Ions\Filesystem\Storage`) is a thin static facade over the container-bound
manager (`filesystem.manager`).

```php
// config/filesystem.php
return [
    'default' => 'local',

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root'   => Path::filesRoot(), // base directory
        ],

        'memory' => [
            'driver' => 'memory',          // ephemeral, in-process (great for tests)
        ],

        's3' => [
            'driver' => 's3',
            'key'    => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'version' => 'latest',
            'root'   => '',                 // key prefix (a.k.a. prefix)
            // optional: 'endpoint', 'use_path_style_endpoint', 'public_url'
        ],

        'ftp' => [
            'driver'   => 'ftp',
            'host'     => env('FTP_HOST'),
            'username' => env('FTP_USERNAME'),
            'password' => env('FTP_PASSWORD'),
            // optional: 'port', 'root', 'ssl', 'timeout', 'passive', ...
        ],

        'sftp' => [
            'driver'   => 'sftp',
            'host'     => env('SFTP_HOST'),
            'username' => env('SFTP_USERNAME'),
            'password' => env('SFTP_PASSWORD'),
            'root'     => '/upload',
            // optional: 'port', 'privateKey', 'passphrase', 'hostFingerprint', ...
        ],
    ],
];
```

### `filesystem.default`

**Type:** `string`  **Default:** `'local'`

Name of the disk returned by `Storage::disk()` / `FilesystemManager::disk()` when
no name is given.

### `filesystem.disks.{name}`

**Type:** `array`

One entry per disk. Each entry MUST contain a `driver` key
(`local` | `memory` | `s3` | `ftp` | `sftp`, or a custom driver registered via
`FilesystemManager::extend()`); an unknown driver throws
`InvalidArgumentException("Unsupported filesystem driver [...]")`. Per-driver options:

- **local** — `root` (base directory).
- **memory** — no options (in-process, non-persistent).
- **s3** — `key`, `secret`, `region`, `bucket`, `version` (default `latest`),
  `root`/`prefix` (key prefix), optional `endpoint`, `use_path_style_endpoint`, `public_url`.
- **ftp** — passed to `FtpConnectionOptions::fromArray()`: `host`, `username`,
  `password`, optional `port`, `root`, `ssl`, `timeout`, `passive`, `utf8`, ...
- **sftp** — passed to `SftpConnectionProvider::fromArray()` (`host`, `username`,
  `password`/`privateKey`, optional `port`, `passphrase`, `hostFingerprint`, ...)
  plus a top-level `root`.

Any extra keys (e.g. `public_url`) are forwarded to the Flysystem `Filesystem`
config, so `Storage::url()` works for disks that declare a `public_url`.

> **Note:** `Ions\Bundles\IonDisk` reads `filesystem.disks.default` (a string under
> `disks`) to pick its adapter and now delegates its operations to the
> `FilesystemManager` while keeping its existing static API.

---

## Database config (`config/database.php`)

`default` names the default connection; `connections` maps connection names to
Illuminate connection arrays (driver, host, database, …). See `DatabaseProvider`.

### `database.query_log`

**Type:** `bool` — **Default:** `false`

When `true`, `DatabaseProvider::boot()` calls `enableQueryLog()` on the default
connection so `debugQuery()` returns the executed statements.

> **Changed in 4.1:** previously the query log was enabled implicitly whenever
> `APP_DEBUG` was truthy. The log buffers every statement in memory for the
> lifetime of the process (unbounded growth in workers/long requests), so it is
> now strictly opt-in. Debuggers that relied on `APP_DEBUG` must set
> `'query_log' => true` in `config/database.php`.

---

## Session config (`config/session.php`)

`Ions\Session\SessionManager` wraps a Symfony `Session` with a config-driven
storage driver. It is bound in the container as `session` by `SessionProvider`
and exposed through the `session()` helper. The `StartSessionMiddleware`
(web stack, before CSRF) starts it at the front of the request and persists it
on the way out. CSRF tokens are stored in this same session (single source of
truth) via `SessionTokenStorage`.

```php
// config/session.php
return [
    'driver' => 'native',          // 'native' | 'array' | 'mock'
    'name' => 'ion_session',       // session cookie name (native driver)
    'lifetime' => 0,               // cookie lifetime in seconds (0 = until browser close)
    'cookie_secure' => 'auto',     // default true; 'auto' = follow request scheme
    'cookie_httponly' => true,     // default true
    'cookie_samesite' => 'lax',    // default 'lax'
];
```

### `session.driver`

**Type:** `string`  **Default:** `'native'`

- `native` — `NativeSessionStorage`; the real PHP session. Use in production/web.
- `array` / `mock` — `MockArraySessionStorage`; in-memory, no real session. Use
  in tests and CLI where starting a native session would emit "headers already
  sent" warnings.

### `session.name` / `session.lifetime` / `session.cookie_*`

Cookie options passed to `NativeSessionStorage` (ignored by the array/mock
driver). `cookie_samesite` accepts `'lax'`, `'strict'`, or `'none'`.

**Secure by default (4.1):** when the `cookie_*` keys are omitted the native
driver applies `cookie_httponly => true`, `cookie_samesite => 'lax'`, and
`cookie_secure => true`. Each can be overridden explicitly. `cookie_secure`
additionally accepts `'auto'` — secure when the current request is HTTPS;
fails secure (`true`) when no request is available at session construction
(CLI, pre-request worker boot). Plain-HTTP dev hosts must set
`'cookie_secure' => false` (or `'auto'`) explicitly.

### The `session()` helper

Mirrors the `config()` helper overloads:

```php
session();                  // the SessionManager instance
session('key');             // get a value
session('key', 'default');  // get with a default
session(['k' => 'v']);      // put one or more values (starts the session)
```

The manager API: `start()`, `get()`, `put()`, `has()`, `forget()`, `all()`,
`flush()`, `flash()`/`getFlash()`, `regenerate()`, `token()`, `getId()`, `save()`,
and `getSession()` (the underlying Symfony session).

## Cache config (`config/cache.php`)

`CacheProvider` binds the shared Illuminate `CacheManager` as `cache`. Stores
are resolved lazily, so only the ones you use are built.

```php
return [
    'default' => 'file',           // store used by cache() with no store name
    'prefix'  => 'ions',           // global key prefix
    'persistent_store' => 'file',  // store for cross-request data (revocations, throttle)
    'stores'  => [
        'array' => ['driver' => 'array', 'serialize' => false],
        'file'  => ['driver' => 'file'],   // path defaults to var/cache/data
        // 'redis' => ['driver' => 'redis', 'connection' => 'cache'],
    ],
];
```

### `cache.default`

Name of the store returned by `cache()` / `cache('key')`. Defaults to `file`
when the key is absent.

### `cache.prefix`

Prefix prepended to every cache key across all stores. Defaults to `ions`.

### `cache.persistent_store`

The store used for data that must survive across requests — JWT revocations
(`revocation_store`) and rate-limit counters (`RateLimitMiddleware`). Both
subsystems now reuse this shared cache instead of building their own file
stores. Defaults to the `file` store (falling back to `cache.default`).

> **Production warning:** `cache.persistent_store` **must** point at a persistent,
> cross-request driver (`file`, `redis`, `database`, …) in production. Do **not**
> use the `array` driver here: it is per-request and in-memory, so JWT revocations
> would never stick (logged-out/refreshed tokens would remain usable until expiry)
> and rate-limit counters would reset on every request (effectively disabling
> throttling). The `array` driver is appropriate only for tests.

### The `cache()` helper

Mirrors the `config()`/`session()` overloads:

```php
cache();                       // the default cache repository
cache('key');                  // get a value
cache('key', 'default');       // get with a default
cache(['k' => 'v'], 60);       // put with a TTL (seconds); omit TTL → forever
cache()->forget('key');        // forget
cache()->store('array');       // a named store as a repository
```

## Events config (`config/events.php`)

`EventProvider` binds the Illuminate event `Dispatcher` as `events` and
auto-registers the listeners declared under `events.listen`.

```php
return [
    'listen' => [
        \Ions\Events\RequestHandled::class => [
            \App\Listeners\LogRequest::class,   // resolved via the container; handle($event) called
        ],
    ],
];
```

### Helpers

```php
event(new RequestHandled($request, $response));   // dispatch an event object
event('my.event', ['payload']);                   // dispatch a named event
listen('my.event', fn ($value) => ...);           // register a listener
```

### Framework event: `RequestHandled`

`Ions\Events\RequestHandled` carries the `Request` and `Response` and is fired
once at the end of `Kernel::handle()` — for both successful and error responses
— in a fire-and-continue manner (listener failures never break the response).

## Queue config (`config/queue.php`)

`QueueProvider` binds the Illuminate `QueueManager` as `queue` with the `sync`,
`database` (and, when a host binds a Redis factory, `redis`) connectors.

```php
return [
    'default' => 'sync',           // sync runs jobs inline
    'connections' => [
        'sync'     => ['driver' => 'sync'],
        'database' => [
            'driver' => 'database', 'table' => 'jobs',
            'queue'  => 'default',  'retry_after' => 90,
        ],
    ],
];
```

### Jobs

Extend `Ions\Queue\Job` (implements `ShouldQueue`, pulls in the Illuminate queue
traits) and implement `handle()`:

```php
final class SendWelcome extends \Ions\Queue\Job {
    public function __construct(private int $userId) {}
    public function handle(): void { /* ... */ }
}

dispatch(new SendWelcome($id));                          // default connection
dispatch((new SendWelcome($id))->onConnection('database'));
```

On `sync`, `handle()` runs immediately. On `database`, the job is persisted to
the `jobs` table and processed by a worker.

### `queue:work`

```bash
ions queue:work                       # work the default connection until empty
ions queue:work database --once       # process a single job, then exit
ions queue:work database --stop-when-empty --tries=3
```

The `database` connection needs the `jobs`/`failed_jobs` tables. A migration
stub is shipped at `src/Queue/stubs/create_jobs_table.stub` — copy it into the
host's `{src|app}/Database` directory (dropping `.stub`) and run `ions migrate`.
