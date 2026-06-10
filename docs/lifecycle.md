# Request Lifecycle

## Boot (`Kernel::boot()`)

Calling `Kernel::boot(?string $basePath = null)` initialises the framework:

1. **Paths** — `Path::setBasePath()` sets the application root (defaults to five directory levels above `vendor/`; pass an explicit path in tests).
2. **Environment** — `vlucas/phpdotenv` loads `.env` via `safeLoad()` (missing file is not fatal).
3. **Container** — `Ions\Container\Container` is instantiated and set on `Illuminate\Support\Facades\Facade`. The `filesystem` / `files` bindings are registered inline because `captureConfig()` needs them before providers run.
4. **Config** — every PHP file in `config/` is loaded into an `Ions\Foundation\Config` instance (a thin wrapper around an associative array). Accessible via `config('key.sub')` anywhere after boot.
5. **Trusted hosts** — if `app.trusted_hosts` is non-empty, `Request::setTrustedHosts()` is called immediately.
6. **Provider bootstrap (two-pass)**:
   - Resolves the provider list: `app.providers` when set (verbatim, no scans); otherwise the `discover:cache` file (`var/cache/providers.php`, one `require`, zero scans — used only when `APP_DEBUG` is off, see [performance.md](performance.md)) or live `Discovery::providers()` — framework defaults + composer `extra.ions.providers` packages + host `{src|app}/Providers/` scan (see [config.md](config.md#appproviders) and [packages.md](packages.md)); pure `Kernel::defaultProviders()` when `app.discovery` is `false`.
   - All `register()` methods run first (every service is bound before any `boot()` runs).
   - All `boot()` methods run second (host providers last, so they can override earlier bindings).
7. **Host `Booting::boot()`** — if `App\Booting` exists in the host app, its static `boot()` method is called, allowing application-level setup after providers.
8. **Timezone** — `date_default_timezone_set(env('TIME_ZONE', 'Africa/Cairo'))`.
9. **Preloads** — files listed in `app.preloads` are `include_once`'d from `src/` (e.g. global helpers).

If any step in the `try` block throws, `Kernel::failBoot()` logs the error (best-effort) and either re-throws (when `APP_DEBUG=true`) or returns a generic 500.

## Handling a Request (`Kernel::handle(Request, string $namespace): SymfonyResponse`)

1. **Group detection** — if the first path segment is `api`, the `api` middleware stack and `Api\` controller namespace are used; otherwise `web`.
2. **Route matching** — `captureRoute()` loads `routes/web.php` or `routes/api.php` (PHP or YAML), then scans `src/Http/` (web) or `app/Api/` (api) for `#[Route]` attribute routes. A built-in `/cron/schedule` route is also added.
3. **Terminal resolution** — for closure routes the closure is wrapped; for string controller routes a `ControllerDispatcher` instance is created.
4. **Middleware stack** — `config('app.middleware')[$group]` is used when present; otherwise `Kernel::defaultMiddleware()` builds the stack. Per-route middleware (from `->middleware([...])`) is appended last (runs closest to the controller).
5. **Pipeline** — `Pipeline::handle(Request)` reduces the middleware chain and calls the terminal.
6. **Response** — the `SymfonyResponse` from the pipeline is returned. Web responses have cache-control headers set (`public`, `max-age=3600`, `must-revalidate`).
7. **Exceptions** — `NoConfigurationException` / `ResourceNotFoundException` → 404; `MethodNotAllowedException` → 405; any other `Throwable` → `ExceptionHandler::render()`.

## Sending the Response

`Kernel::run(?Request $request, string $namespace): void` captures the request (if not provided), calls `handle()`, then calls `Kernel::sendResponse()`, which applies `SecurityHeaders::apply()` and calls `Response::send()`.

### Entry-point summary

| Method | Use case |
|---|---|
| `Kernel::boot()` | Always call first in the front controller |
| `Kernel::run()` | New front controllers — handles + sends |
| `Kernel::handle(Request)` | When you need the `Response` object (e.g. tests) |
| `Kernel::make()` | BC shim — delegates to `run()` |

## Error rendering (`Ions\Http\ExceptionHandler`)

Every `Throwable` caught by `Kernel::handle()` becomes a `Response`:

- `ValidationException` → **422** (`{message, errors}` for JSON, HTML otherwise).
- `HttpExceptionInterface` (e.g. from `abort()`) keeps its status and its message — those messages are deliberate and client-facing.
- Any other `Throwable` → **500**; its message is shown only when `APP_DEBUG` is truthy.
- JSON is selected when `Request::wantsJson()` or the first path segment is `api`; HTML otherwise.

### Debug error page (APP_DEBUG only)

In debug mode the HTML path renders `Ions\Http\DebugPage` — a single self-contained document (inline CSS, no JS, no external assets, no Whoops/Ignition dependency) with:

- exception class, message, status header;
- a ±10-line **source excerpt** around the throwing line (escaped, error line highlighted);
- the `getPrevious()` **chain** (class + message + file:line, capped at 5);
- a **stack trace** (max 50 frames) with paths shortened relative to the host base path and vendor frames visually de-emphasized;
- a **request summary**: method, path, route name (when resolvable), client IP, headers, query and body params.

**Redaction & deliberate minimalism.** Headers and params pass the same key redaction as the log `RedactionProcessor` (`password`/`token`/`secret`/`authorization`/`api_key`, …) **plus** `Cookie`, so raw `Authorization`/`Cookie` values are never printed; long values are truncated. The page deliberately renders **no env vars, no config dump, no server superglobals, and no frame arguments** — even in debug mode the surface is kept minimal against accidental info-leak (screenshots, shared dev URLs).

The renderer is failure-safe: each section is individually guarded and the whole page falls back to the previous minimal `<h1>/<pre>` block if rendering ever throws. Production output (`APP_DEBUG` off) is unchanged: a bare `<h1>{status} {text}</h1>` with non-HTTP exception messages hidden.
