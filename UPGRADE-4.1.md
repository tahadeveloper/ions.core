# Upgrading to 4.1 (draft — release notes assembled in Phase 8.7)

## Security default flips (read these first)

### Session cookies are secure by default (D8-1)

Before 4.1, omitting the `cookie_*` keys from `config/session.php` left the
native session cookie with raw PHP defaults (no `Secure`, no `SameSite`,
httponly per php.ini). In 4.1 the native driver defaults to:

| Option | 4.1 default |
|---|---|
| `cookie_httponly` | `true` |
| `cookie_samesite` | `'lax'` |
| `cookie_secure` | `true` |

Every default can still be overridden by setting the key explicitly in
`config/session.php`. `cookie_secure` also accepts `'auto'`: the flag follows
the scheme of the current request (HTTPS → secure). When no request is
available at session construction (CLI, pre-request worker boot) `'auto'`
fails secure (`true`).

**Action:** if your app is served over plain HTTP (local dev), set
`'cookie_secure' => false` (or `'auto'`) explicitly — otherwise browsers will
not send the session cookie and logins/CSRF will fail.

> **Caveat — TLS-terminating reverse proxies:** the framework has no
> trusted-proxy support yet, so behind a proxy that terminates TLS and
> forwards plain HTTP (nginx, a load balancer) `Request::isSecure()` is
> `false` and `'auto'` resolves to an **insecure** cookie. Do not use
> `'auto'` there — keep the default `true`. The same limitation applies to
> HSTS via `app.security.hsts`, which is only emitted on requests the
> framework sees as HTTPS.

### Login regenerates the session id (fixation hardening)

`Ions\Auth\Http\AuthController::login` now calls `SessionManager::regenerate()`
after a successful credential check **when a framework session is bound and
started** (web-originated logins). Session data is preserved; only the id
rotates. Stateless API logins without a started session are unaffected.

### CORS is deny-by-default (D8-1)

Before 4.1, `CorsMiddleware` defaulted to `origins = ['*']`: every response
carried `Access-Control-Allow-Origin: *`. In 4.1 the default is `origins = []`
(deny): with no configured origins **no CORS headers are emitted at all**, and
preflight `OPTIONS` requests receive a plain `204` without `Access-Control-*`
headers.

**Action:** hosts that serve cross-origin traffic must now configure
`config/app.php`:

```php
'cors' => [
    'origins' => ['https://app.example.com'],  // or ['*'] for a public API
    // 'credentials' => true,                  // see below
],
```

`Access-Control-Allow-Credentials: true` is emitted only when
`app.cors.credentials` is explicitly `true` **and** the origin list is not the
`['*']` wildcard (the Fetch spec forbids credentials with a wildcard origin —
that combination silently drops the credentials header).

### New response headers: HSTS + Permissions-Policy

`SecurityHeaders::apply()` now also emits:

- `Strict-Transport-Security: max-age=31536000; includeSubDomains` — **HTTPS
  requests only**. Override with a string at `app.security.hsts`, or disable
  with `false`.
- `Permissions-Policy: camera=(), geolocation=(), microphone=()` — override at
  `app.security.permissions_policy`, or disable with `false`.

Both follow the CSP rule: a header already set by the caller is never
overwritten. If your app relies on browser camera/geolocation/microphone
access, set a matching `permissions_policy`.

### Uploads: magic-bytes content validation

`IonUpload::store()`, `IonDisk::put()` and `IonDisk::putFile()` now verify —
after the extension allow-list — that the actual content's `finfo` MIME
agrees with the claimed extension (e.g. PHP source named `.jpg` is rejected
with "File content does not match its extension"). The extension→MIME map is
configurable at `app.uploads.mime_map` (merged over the built-in defaults);
extensions absent from the map are accepted on the extension gate alone. See
`docs/config.md`.

### Forgot-password requests are throttled per email

`AuthController::forgotPassword` now applies a per-(email+IP) limit of
3 requests / 10 minutes via the shared cache (configure with
`app.auth.forgot_throttle = ['max' => 3, 'decay' => 600]`). Throttled requests
receive **429** with a generic message and a `Retry-After` header — the same
shape as the route-level `throttle` middleware, and still enumeration-safe.

### Log context redaction

Loggers built by `Logs::create()` now run `Ions\Bundles\RedactionProcessor`:
context keys matching *password / passwd / token / secret / authorization /
api_key* (case-insensitive, recursive through nested arrays) are masked to
`[REDACTED]` before reaching the log file. If you intentionally log such
values, build a bare Monolog `Logger` yourself.

## Behavior changes

### Unresolvable per-route middleware now fails the request

Before 4.1, a per-route middleware name that could not be resolved (unknown
alias, missing class, or a constructor that threw) was logged and **silently
dropped in production** — the route was served without it. In 4.1 resolution
failure always throws (rendered as a 500; generic body in production with the
detail logged, full error in debug). This is deliberately fail-closed: a
typo'd `'signed'` or `'throttle'` alias must not leave a guarded route open.
Group middleware stacks are pre-constructed instances and are unaffected.

**Action:** if a production log shows dropped-middleware warnings today, fix
the alias/class before upgrading — those routes will 500 under 4.1.

### Query logging is no longer implied by APP_DEBUG

Before 4.1, `DatabaseProvider::boot()` called `enableQueryLog()` on the
default connection whenever `APP_DEBUG` was truthy. The query log buffers
every executed statement in memory for the lifetime of the process, which
grows without bound in long-running processes.

In 4.1 the log is **opt-in**:

```php
// config/database.php
return [
    'default' => 'mysql',
    'query_log' => true,   // explicit opt-in (default false)
    // ...
];
```

If you relied on `APP_DEBUG=true` to make `debugQuery()` return statements,
add `'query_log' => true` to `config/database.php`.

### Routes are captured once per process

`Kernel::handle()` no longer re-requires the route files and re-scans
attribute routes on every call; the per-group collection is memoized for the
process lifetime and rebuilt on every `Kernel::boot()`. Classic FPM apps see
no functional difference (one process = one request); code that mutated
`Kernel::RouteCollection()` *between* `handle()` calls in the same process
must re-boot the kernel to have the change picked up.

### Richer debug error page (debug mode only)

With `APP_DEBUG=true`, HTML errors now render a full debug page (`Ions\Http\DebugPage`: source excerpt, stack trace, `getPrevious()` chain, redacted request summary) instead of the bare `<h1>/<pre>` block. Production output (`APP_DEBUG` off) is byte-for-byte unchanged, and JSON/api error responses are unaffected. See `docs/lifecycle.md` → "Error rendering".

## New (optional) production caches

```bash
ions optimize           # route:cache + config:cache
ions optimize:clear     # clears both + the compiled Twig cache
ions route:cache        # compiled route matching (CompiledUrlMatcher)
ions config:cache       # one merged config file
ions preload:generate   # opcache.preload file for the framework hot path
```

All caches are ignored while `APP_DEBUG` is truthy. Closure-based routes or
config values cannot be cached — the commands fail with the offending
route/key named. See [docs/performance.md](docs/performance.md).

## Worker mode (EXPERIMENTAL)

4.1 adds a per-request reset lifecycle for persistent runtimes
(FrankenPHP/RoadRunner-style: boot once, handle many requests in one
process):

- `Kernel::resetForRequest()` clears per-request state (request/response
  statics, the framework session + CSRF token storage, the per-request Twig
  globals `_csrf_token`/`_trans`/`appUrl`, the query log) while keeping boot
  state (config, container singletons, route memo, the Twig Environment).
- `Ions\Runtime\WorkerRunner` (experimental) drives the
  reset-then-handle loop over provider/emitter callables, with optional
  `maxRequests` recycling.

See [docs/worker-mode.md](docs/worker-mode.md) for the state table, usage,
a FrankenPHP example, and the known limitations.

### Kernel::request() now tracks the handled request

`Kernel::handle($request)` now points the shared `Kernel::request()` static at
the request actually being handled (previously it stayed at the boot-time
capture from superglobals). Classic FPM apps see no difference — the captured
request *is* the handled request there — but code that compared
`Kernel::request()` against a separately constructed request object should be
reviewed.
