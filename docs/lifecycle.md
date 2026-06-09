# Request Lifecycle

## Boot (`Kernel::boot()`)

Calling `Kernel::boot(?string $basePath = null)` initialises the framework:

1. **Paths** — `Path::setBasePath()` sets the application root (defaults to five directory levels above `vendor/`; pass an explicit path in tests).
2. **Environment** — `vlucas/phpdotenv` loads `.env` via `safeLoad()` (missing file is not fatal).
3. **Container** — `Ions\Container\Container` is instantiated and set on `Illuminate\Support\Facades\Facade`. The `filesystem` / `files` bindings are registered inline because `captureConfig()` needs them before providers run.
4. **Config** — every PHP file in `config/` is loaded into an `Ions\Foundation\Config` instance (a thin wrapper around an associative array). Accessible via `config('key.sub')` anywhere after boot.
5. **Trusted hosts** — if `app.trusted_hosts` is non-empty, `Request::setTrustedHosts()` is called immediately.
6. **Provider bootstrap (two-pass)**:
   - Reads `app.providers` from config, falling back to `Kernel::defaultProviders()`: `ConfigProvider`, `FilesystemProvider`, `DatabaseProvider`, `AuthProvider`, `MailProvider`, `ViewProvider`.
   - All `register()` methods run first (every service is bound before any `boot()` runs).
   - All `boot()` methods run second.
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
