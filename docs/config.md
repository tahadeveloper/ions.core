# Configuration Reference

This is the canonical reference for all framework config keys. Keys live in PHP files under `config/` in the host application. The file name becomes the top-level namespace: `config/app.php` → `app.*`, `config/auth.php` → `auth.*`, etc.

> The older `docs/phase2-config.md` file is preserved for historical context but this document supersedes it as the single canonical config reference.

---

## Typed accessors

The `config()` helper returns the `Ions\Foundation\Config` instance when called with no arguments. Alongside the untyped `get()`/`config('key', $default)` overloads, `Config` exposes **assertion-style typed getters** (mirroring Laravel 11's `Config::string()` family):

```php
config()->string('app.name');            // string — or throws
config()->integer('app.ratelimit.max');  // int    — int() alias also available
config()->boolean('app.csrf.enabled');   // bool   — bool() alias also available
config()->array('app.providers');        // array
config()->float('app.jwt.leeway');       // float
```

Each accessor fetches the value via `get($key, $default)` and **throws `InvalidArgumentException`** when the resolved value is not of the expected type:

```php
// config/app.php: 'workers' => '4'   (oops — a string)
config()->integer('app.workers');
// InvalidArgumentException: Configuration value for key [app.workers]
// must be an integer, string given.
```

Rules:

- **No coercion.** `'1'` is not an int, `0`/`1` are not bools, and an int is not a float. A wrongly-typed `.env`-sourced value fails loudly at the read site instead of silently mis-behaving downstream — this kills a whole class of config bugs. (Tip: for `float()` keys write `30.0` in the config file, or use `integer()` if the key is conceptually an int.)
- **Missing key + typed default** → the default is returned: `config()->string('app.name', 'Ions')`.
- **Missing key without a default** resolves to `null`, which is a type mismatch → throws. This is deliberate: either pass an explicit default or guarantee the key exists.
- A stored `null` value likewise throws.

---

## `app.providers`

**Type:** `array` of FQCN strings extending `Ions\Container\ServiceProvider`

**Default (when absent):** **auto-discovery** via `Ions\Foundation\Discovery::providers()`, which merges, in order:

1. **Framework defaults** — `Kernel::defaultProviders()` (the 13 built-in `Ions\Providers\*` providers: Config, Filesystem, Session, Database, Cache, Event, Queue, Auth, Mail, Notification, HttpClient, Security, View).
2. **Package providers** — every installed composer package declaring `extra.ions.providers` in its composer.json (see [packages.md](packages.md)). Read once per process from `vendor/composer/installed.json` and memoized.
3. **Host providers** — every concrete `ServiceProvider` subclass in the host's `{app|src}/Providers/` directory (single `glob()` per boot; the `app/` → `src/` fallback applies — `app/` wins since 4.2).

The merged list is de-duplicated (first occurrence wins). Host providers run **last**, so they can override bindings registered by framework or package providers. Abstract classes and non-provider classes in the scanned locations are skipped.

This means a host app normally needs **no `providers` key at all** — drop a provider into `app/Providers/` (or install a package that declares `extra.ions.providers`) and it is registered and booted automatically.

In production, run `ions discover:cache` (included in `ions optimize`) to freeze the discovered list into `var/cache/providers.php`; boot then loads it with one `require` and skips all scans. Debug bypasses the cache; re-run it after composer changes or provider edits — see [performance.md](performance.md).

> **Security note:** `composer require` means code running at boot — a package's `extra.ions.providers` providers register and boot on every request with full framework access. Review the `extra.ions.providers` of packages you install. Escape hatches: `app.dont_discover` (skip specific packages), `app.discovery => false` (no scans at all), or an explicit `app.providers` list (full control).

**Setting this key replaces everything** — no discovery scan runs at all, and the list (including any framework providers you still need) is used verbatim. Full explicit control:

```php
'providers' => [
    \Ions\Providers\FilesystemProvider::class,
    \Ions\Providers\DatabaseProvider::class,
    \Ions\Providers\AuthProvider::class,
    \App\Providers\AppServiceProvider::class,
],
```

---

## `app.discovery`

**Type:** `bool`  **Default:** `true`

Escape hatch for provider auto-discovery. When `false` (and `app.providers` is not set), the kernel registers **only** `Kernel::defaultProviders()` — neither the host `{app|src}/Providers/` scan nor the composer `extra.ions.providers` scan runs.

Ignored when `app.providers` is set (an explicit list already bypasses discovery).

```php
'discovery' => false, // pure framework defaults, no scans
```

---

## `app.dont_discover`

**Type:** `array` of composer package names  **Default:** `[]`

Opt out of provider auto-discovery for specific composer packages while keeping discovery on for everything else. Each entry is an **exact `vendor/package` name match** against the installed package's composer name — no prefixes or wildcards:

```php
'dont_discover' => [
    'acme/ions-stripe', // its extra.ions.providers are ignored
],
```

The package's `extra.ions.providers` are simply skipped; the host can still register the providers it wants explicitly. Ignored when `app.providers` is set or `app.discovery` is `false` (no package scan runs in either case). See the security note under [`app.providers`](#appproviders).

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

## `app.trusted_proxies`

**Type:** `array` of IPs/CIDRs, or `['*']`  **Default:** `[]`

Reverse proxies whose `X-Forwarded-*` headers the framework should trust,
passed to Symfony's `Request::setTrustedProxies()` at boot and re-applied per
request by `Kernel::handle()`. Behind a TLS-terminating proxy or load
balancer this is what makes `Request::isSecure()`, `getClientIp()`, HSTS
([`app.security.hsts`](#appsecurityhsts)) and `session.cookie_secure =>
'auto'` see the real client connection instead of the proxy's plain-HTTP hop.

```php
// Exact proxy IPs or CIDR ranges:
'trusted_proxies' => ['10.0.0.0/8'],

// Single-LB case: trust whatever peer connects directly (Laravel's '*'):
'trusted_proxies' => ['*'],
```

Only list proxies **you** control — trusting arbitrary peers lets clients
spoof their IP and scheme. `'*'` is only safe when the app is **never**
directly reachable (a private-network load balancer fronts every request):
a client that connects directly then *is* the trusted proxy and can spoof
its IP and scheme. An empty array (the default) trusts nothing:
`X-Forwarded-*` headers from clients are ignored.

---

## `app.trusted_proxy_headers`

**Type:** `string` or `int` bitmask  **Default:** `'xff'`

Which forwarded headers to trust from the proxies above:

| Value | Trusted headers |
|---|---|
| `'xff'` (default) | `X-Forwarded-For` / `-Host` / `-Port` / `-Proto` |
| `'aws-elb'` | the set sent by AWS ELB/ALB (no `X-Forwarded-Host`) |
| `'traefik'` | all `X-Forwarded-*` headers sent by Traefik |
| `'forwarded'` | the RFC 7239 `Forwarded` header |

Power users can pass any `Request::HEADER_*` int bitmask directly. Strings
are matched case-insensitively; unknown strings throw at boot
(`InvalidArgumentException`) rather than silently falling back to the wider
`'xff'` set.

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

## `app.uploads.mime_map`

**Type:** `array<string, string[]>`  **Default:** built-in map (jpg/jpeg → image/jpeg, png → image/png, pdf → application/pdf, txt/csv → `text/*`, zip → application/zip, doc/docx/xls/xlsx → office types, …)

Per-extension MIME agreement map for magic-bytes content validation (4.1).
After the extension allow-list passes, `UploadValidator::isContentValid()`
checks that the `finfo` MIME of the actual content agrees with the claimed
extension — a `.jpg` containing PHP source is rejected. Entries here are
merged **over** the defaults (an entry replaces the whole list for that
extension). A `type/*` value matches any subtype.

Extensions absent from the map are accepted on the extension gate alone, so
uncommon types are not bricked — add a mapping to opt them into content
validation.

```php
'uploads' => [
    'mime_map' => [
        'json' => ['application/json', 'text/*'],
    ],
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

## `app.auth.forgot_throttle`

**Type:** `array{max?: int, decay?: int}`  **Default:** `['max' => 3, 'decay' => 600]`

Per-(email+IP) throttle applied inside `AuthController::forgotPassword()` on
top of any route-level `throttle` middleware. After `max` requests for the
same email from the same IP within `decay` seconds, further requests receive
HTTP 429 with a generic message and a `Retry-After` header (enumeration-safe:
the limit applies whether or not the account exists). Backed by the shared
cache; skipped gracefully when no cache is bound.

```php
'auth' => [
    'forgot_throttle' => ['max' => 3, 'decay' => 600],
],
```

---

## `app.preloads`

**Type:** `string[]`  **Default:** `[]`

Paths relative to the host code directory (`app/`, or `src/` on the legacy layout) that are `include_once`'d during boot (e.g. global helpers or aliases).

---

## Laravel-compatible path globals

Ions registers the familiar Laravel path helpers in `src/helpers.php`, each mapped to `Ions\Bundles\Path`: `base_path()`, `app_path()`, `config_path()`, `database_path()`, `public_path()`, `storage_path()` (Ions storage lives under `var/`) and `resource_path()` (resolves `<root>/resources`). They follow Laravel semantics — calling with no argument returns the directory root with no trailing slash; a sub-path is joined with a single separator. These exist so Illuminate components that call the globals directly (e.g. file-based SQLite via `SQLiteConnector`) work out of the box.

---

## `app.schedule_class`

**Type:** `class-string`  **Default:** `'App\Schedule'`

The host's schedule definition class for the cron scheduler (see [scheduler.md](scheduler.md)). When its `boot()` accepts an `Ions\Schedule\Scheduler`, the provider invokes it on the first resolve of the `'schedule'` binding; a legacy zero-parameter `boot()` stays wired to the `/cron/schedule` controller dispatch instead.

---

## `app.forms.dont_flash`

**Type:** `list<string>`  **Default:** `['password', 'password_confirmation', 'current_password']`

Input fields excluded from `withInput()` flashing (and the automatic
validation-failure flash) — see [forms.md](forms.md).

---

## `app.health.enabled`

**Type:** `bool` — **Default:** `true`

Controls the built-in `GET /up` health endpoint (4.3, see
[console.md](console.md#the-up-health-endpoint)). Setting this **explicitly to
`false`** removes the route from the collection entirely — `/up` then 404s like
any unknown path. The endpoint answers a plain `200 ok` and is marked
`Cache-Control: no-store` so CDNs can never serve a cached "ok" during an
outage.

## `app.health.token`

**Type:** `string|null` — **Default:** `null`

Token gating `GET /up?checks=1`, which runs the full `ions doctor` checks and
answers their JSON (`{checks, summary, ok}`) over HTTP. Doctor output names
filesystem paths and config state, so the checks variant is locked unless this
token is set **and** matches the `?token=` query parameter (constant-time
compare); otherwise the request is rejected with 403. Leave `null` to keep the
liveness probe only. Set it from the environment, e.g.
`'token' => env('HEALTH_TOKEN')`.

## `app.debug_toolbar`

**Type:** `bool` — **Default:** `true`

In-debug escape hatch for the debug toolbar (see
[performance.md](performance.md#debug-toolbar-debug-only)). The toolbar
middleware is only ever attached to the web stack when `APP_DEBUG` is truthy —
production never constructs it regardless of this key. Set `false` to hide the
bar while debugging.

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

**Type:** `array<string, string>`  **Default:** `[]`

Named Twig namespaces, registered once on the shared environment's
`FilesystemLoader` when it is first built. Keys are namespace names,
values are template directories:

```php
'twig' => [
    'source' => Path::views(''),
    'cache'  => Path::templates(''),
    'paths'  => [
        'admin' => 'views/admin',                      // relative => host root
        'mail'  => 'views/mail',
        'pkg'   => '/abs/path/vendor/acme/pkg/views',  // absolute kept as-is
    ],
],
```

Templates address a namespace as `@admin/users/index.twig`; the `view()`
helper accepts the dotted form too (`view('@admin.users.index')`). Relative
directories resolve from the **host root**; absolute paths (e.g. vendor
packages) are used untouched. A missing directory never breaks boot: the
namespace is skipped, recorded in `ViewFactory::$loaderErrors` and logged
to `view.log`.

Pre-4.2 list entries (`'paths' => ['admin']`) keep their legacy behavior:
the value is both the namespace name and a folder under `views/`.

See [views.md](views.md) for the full view layer (helper, controller-relative
resolution, dispatcher bridge).

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
no name is given. When unset, the manager falls back to the legacy IonDisk key
`filesystem.disks.default` (traditionally fed from the `FILESYSTEM_DISK` env)
before defaulting to `'local'`.

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
config, so `Storage::url()` works for disks that declare a `public_url` (or its
Laravel-style alias `url`); disks with neither fall back to
`config('app.app_url')` — see [filesystem.md](filesystem.md).

> **Note:** `Ions\Bundles\IonDisk` reads `filesystem.disks.default` (a string under
> `disks`) to pick its disk type and resolves its disks through the shared
> `FilesystemManager` — so `Storage::fake()` intercepts it — while keeping its
> existing static API. Removal candidate for 5.0; prefer `Ions\Filesystem\Storage`.

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

### `database.strict`

**Type:** `bool` — **Default:** `true` *(only effective in debug)*

ORM strict mode (4.3). When `APP_DEBUG` is truthy and this key is not `false`,
`DatabaseProvider::boot()` enables Eloquent's development guards:

- `Model::preventLazyLoading(true)` — lazy-loading a relation off a model that
  was hydrated as part of a **multi-model result set** throws
  `Illuminate\Database\LazyLoadingViolationException` naming the relation (a
  single `first()`/`find()` model never throws — that is upstream Eloquent
  semantics, since one lazy load is not an N+1).
- `Model::preventSilentlyDiscardingAttributes(true)` — `fill()`ing an attribute
  blocked by `$fillable` throws instead of silently dropping it.

With `APP_DEBUG` off the guards are **always disabled**, regardless of this
key — production behavior never changes. The statics are re-set on every boot
with the freshly computed value, so worker re-boots and test runs self-correct.

Not to be confused with the per-connection `'strict' => true` MySQL mode flag
inside `connections.mysql`.

> **Changed in 4.3:** strict mode defaults to ON in debug. Upgraders whose dev
> code lazy-loads relations from collections will now see
> `LazyLoadingViolationException` — fix with eager loading (`->with(...)`) or
> opt out via `'strict' => false`. See UPGRADE-4.3.

### `database.nplusone.enabled`

**Type:** `bool` — **Default:** `true`

Escape hatch for the debug-only N+1 query detector. The detector only ever
runs when `APP_DEBUG` is truthy **and** `database.query_log` is `true`;
setting this to `false` prevents `DatabaseProvider::boot()` from attaching the
`DetectNPlusOne` listener even then. Warnings go to
`var/logs/performance.log`. See [performance.md](performance.md#n1-query-detector-debug-only).

### `database.nplusone.threshold`

**Type:** `int` — **Default:** `5`

How many times one normalized SELECT pattern must repeat within a single
request before the N+1 warning fires.

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

> **Behind a TLS-terminating reverse proxy** configure
> [`app.trusted_proxies`](#apptrusted_proxies) (4.3+) — with it,
> `X-Forwarded-Proto` from your proxy makes `Request::isSecure()` `true`, so
> `'auto'` resolves to a **secure** cookie and HSTS via
> [`app.security.hsts`](#appsecurityhsts) is emitted. Without it the request
> looks like plain HTTP there: do **not** use `'auto'` — keep the default
> `true`.

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

## Logging config (`config/logging.php`)

`LogProvider` binds the channel-based `Ions\Log\LogManager` as `log`, consumed
through the `Ions\Support\Log` facade. Channels are built lazily and memoized.
Full guide: [logging.md](logging.md).

```php
return [
    'default' => env('LOG_CHANNEL', 'app'),   // channel used by Log::info() etc.

    'channels' => [
        'app'    => ['driver' => 'single', 'path' => 'app.log', 'level' => 'debug'],
        'daily'  => ['driver' => 'daily',  'path' => 'app.log', 'days' => 14],
        'stderr' => ['driver' => 'stderr', 'level' => 'warning'],
        'stack'  => ['driver' => 'stack',  'channels' => ['app', 'stderr']],
    ],
];
```

### `logging.default`

Channel used when none is named (`Log::info()`, `Log::channel()` with no
argument). Defaults to `app`.

### `logging.channels`

Map of channel name → definition. Drivers: `single` (one file), `daily`
(`RotatingFileHandler`, pruned after `days`, default 7), `stderr`
(`php://stderr`), `stack` (fan-out to the named member `channels`). Relative
`path` values resolve under `var/logs/`; absolute paths are kept. `level`
defaults to `debug`. Unknown channels/drivers/levels throw
`InvalidArgumentException`. When the whole file is absent, a built-in `app`
channel (single → `var/logs/app.log`) keeps zero-config logging working.
Every channel masks secret context values and stamps `extra.request_id`
(see [logging.md](logging.md)).

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
    'failed' => [                  // failed-job storage (all keys optional)
        // 'database' is ONLY for legacy tables without a uuid column — with
        // the bundled stub (uuid NOT NULL) it silently loses failure records.
        'driver'   => 'database-uuids', // 'database-uuids' | 'database' | 'null'
        'database' => null,             // connection name (null = default)
        'table'    => 'failed_jobs',
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
host's `database/schemas/` directory (4.4+ layout; `{app|src}/Database/Schema`
on the legacy layout), dropping `.stub`, and run `ions migrate`.

## Notifications config (`config/notifications.php`)

Optional — both keys have working defaults, so the file is only needed to
override them. See [notifications.md](notifications.md).

```php
return [
    // Table the 'database' channel inserts into (default: 'notifications';
    // DDL stub: src/Notifications/stubs/create_notifications_table.stub).
    'table' => 'notifications',

    // Custom channel map, merged OVER the built-ins ('mail', 'database') —
    // name => class implementing Ions\Notifications\Contracts\Channel.
    'channels' => [
        // 'slack' => \App\Notifications\SlackChannel::class,
    ],
];
```
