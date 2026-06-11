# Upgrading to 4.3

4.3.0 is additive for almost every host — the new facilities (trusted
proxies, implicit route model binding, pagination + the web form flow with
the fluent `redirect()`, Gate/policy authorization, queue retries + failed-job
recovery, the `/up` health endpoint, the debug toolbar, custom error pages,
channel logging, fluent routing, the richer `Storage` API, the PSR-15
adapter, maintenance mode and `ions serve`) are catalogued in the
[CHANGELOG 4.3.0 section](CHANGELOG.md#430---2026-06-11), with full guides in
[docs/forms.md](docs/forms.md), [docs/auth.md](docs/auth.md#authorization-gate--policies),
[docs/routing.md](docs/routing.md), [docs/logging.md](docs/logging.md),
[docs/filesystem.md](docs/filesystem.md), [docs/views.md](docs/views.md),
[docs/cache-queue-events.md](docs/cache-queue-events.md),
[docs/middleware.md](docs/middleware.md) and [docs/deploy.md](docs/deploy.md).
This document covers only the behavior changes you may need to act on.

Three new composer dependencies install automatically with the upgrade, all
serving the PSR-15 adapter: `nyholm/psr7` `^1.8`,
`symfony/psr-http-message-bridge` `^7.4` and `psr/http-server-middleware`
`^1.0` (small, MIT, standard). No action needed.

## Behavior changes

### ORM strict mode is ON in debug

With `APP_DEBUG=true`, `DatabaseProvider::boot()` now enables Eloquent's
strict mode: a **lazy relation access throws**
`LazyLoadingViolationException` (naming the model and relation) instead of
silently querying, and attributes **silently discarded by `$fillable`
throw** instead of vanishing. Production (`APP_DEBUG=false`) is always
relaxed, regardless of config — only development surfaces change.

**Who is affected:** dev/test environments of hosts whose code lazy-loads
relations (`$post->comments` without `with('comments')`) or mass-assigns
keys missing from `$fillable`. Those now fail loudly in debug — which is the
point: fix the N+1 or the `$fillable` list.

**Escape hatch:** `'strict' => false` in `config/database.php` restores the
pre-4.3 relaxed behavior in debug.

One upstream nuance: Eloquent only arms models hydrated from **multi-model**
results, so a lazy load off a single `first()`/`find()` model never throws —
the collection case (the actual N+1 shape) is what's caught. See
[docs/config.md](docs/config.md#databasestrict).

### Failed web validation: 302 redirect back instead of a 422 HTML page

A thrown Illuminate `ValidationException` — a failed FormRequest, a manual
`validate()` call, anywhere — is content-negotiated by the ExceptionHandler:

| Request | 4.2 | 4.3 |
|---|---|---|
| `Accept: application/json`, or first path segment `api` | 422 JSON `{message, errors}` | **unchanged, byte-identical** |
| anything else (web/HTML form POST) | 422 HTML error page | **302 back** with errors + input flashed |

The redirect targets the same-origin Referer (`/` fallback) and flashes the
error bag and request input for the next render's `errors()`/`old()` helpers
(password-ish fields excluded via `app.forms.dont_flash`). This is the
standard browser form round trip — see [docs/forms.md](docs/forms.md).

**Action:** if a specific web endpoint must keep the 422 (an XHR/fetch form
posted outside `/api`), have the client send `Accept: application/json` —
`wantsJson()` keeps the JSON contract. Tests asserting `422` on web POSTs
should now assert the redirect (`assertRedirect()`) or send the JSON Accept
header.

### Route model binding: bound-or-404 replaces the empty model

An action (or closure-route) parameter whose **type is an Eloquent `Model`
subclass** and whose **name matches a route placeholder** — e.g.
`show(Widget $widget)` on `/widgets/{widget}` — previously received a brand
new, **empty** model instance from the container (the placeholder value was
ignored). In 4.3 it receives the record fetched by the model's route key
(`getRouteKeyName()`, default primary key), and:

- a miss is a **404** (nullable parameter: null is injected instead);
- the route now performs a **database query it previously didn't**;
- with the `'db'` engine not booted, the binding throws a clear
  `RuntimeException` naming the model class (rendered as a 500).

**Who is affected:** only signatures that combine a Model type-hint with a
placeholder-matching name — a combination that previously yielded a useless
empty model, so most hosts were not writing it. If you did rely on it,
rename the parameter (or drop the type-hint) to keep the container-made
instance. Model hints whose name matches **no** placeholder are unchanged
(still container-made). See
[docs/controllers.md](docs/controllers.md#route-model-binding).

### The CSRF 419 is thrown, not returned

`CsrfMiddleware` now throws `HttpException(419, 'CSRF token mismatch.')`
through the ExceptionHandler instead of returning a bare
`Response('CSRF token mismatch.', 419)`. Clients still receive a 419; the
gains are theming (`views/errors/419.twig` now renders it) and a proper JSON
shape if the failure ever occurs on a JSON-accepting request.

**Who is affected:** hosts that wrap or replace the web stack and inspect
the middleware's direct **return value** for the 419, and tests pinning the
exact `text/html`-less body `CSRF token mismatch.` — the message is now the
HttpException message rendered through the standard error path (custom
`errors/419.twig` wins when present).

### `HttpException` headers now reach the response

Headers attached to a thrown `HttpException` — `abort()`-style flows,
`Retry-After` on the maintenance 503, `Allow`, `WWW-Authenticate` — were
previously dropped by the ExceptionHandler; they are now set on the rendered
JSON/HTML response. Purely additive unless something relied on those headers
being stripped.

### Custom error pages: only if you have the templates

The production HTML error path now checks `views/errors/{status}.twig`, then
`views/errors/{4xx|5xx}.twig`, before the built-in minimal page. Hosts
without such templates are byte-identical. A host that **coincidentally**
has templates at those paths will see them start rendering for matching
errors — move or rename them if that's unwanted. Debug mode (DebugPage) and
API/JSON errors are untouched. See
[docs/views.md](docs/views.md#custom-error-pages-43).

### `IonDisk`/`IonUpload` resolve disks through the FilesystemManager

The legacy static disk APIs keep their behavior and signatures, but disk
acquisition is now routed through the shared `FilesystemManager` (container
binding `filesystem.manager`):

- **`Storage::fake()` now intercepts IonDisk and IonUpload** reads/writes in
  tests — the 8.4 caveat is closed. Tests that previously asserted against
  the real `public/uploads` while a fake was active will see the fake win.
- `IonDisk`'s `local` disk resolves as the manager's **named `local` disk**
  (shared and memoized with `Storage::disk('local')`) when
  `filesystem.disks.local.driver` is declared; legacy driverless shapes and
  s3 with runtime-mutable bucket/basePath are still built from IonDisk's own
  config view, cached per shape.
- The default-disk name resolves `filesystem.default` → the legacy
  `filesystem.disks.default` string (the `FILESYSTEM_DISK` env convention) →
  `'local'` — preserved, now also honoured by `Storage`.

Hosts that mutated manager state at runtime (e.g. `set()` custom disks)
should note IonDisk now sees those disks too. IonDisk is a removal candidate
for 5.0 — prefer `Ions\Filesystem\Storage` in new code. See
[docs/filesystem.md](docs/filesystem.md).

### Smaller notes

- **Redirect caching:** the kernel's web default (`public, max-age=3600`) is
  no longer applied to 3xx responses (per-user `Location` values must not be
  served from shared caches), and responses carrying `no-store` keep it.
- **Session save on exceptions:** `StartSessionMiddleware` now saves the
  session in `finally`, so flash data written before a throw survives into
  the next request (it was previously lost).
- **Built-in `/up` route:** new; a host route on `/up` still wins (built-ins
  are appended after host routes), and `app.health.enabled => false` removes
  it entirely.
- **`app.trusted_proxies` / `app.trusted_proxy_headers`:** new config — no
  proxy trust without it, exactly as before. Note the fail-closed parsing: an
  unknown header-set string (e.g. a typo'd `'aws_elb'`) throws instead of
  silently using the permissive `'xff'` set.
- **Log lines gain `extra.request_id`:** every channel — including legacy
  `Logs::create()` loggers — stamps a per-request correlation id into the
  Monolog `extra` field. Line shape is otherwise unchanged; log parsers that
  pinned an empty `extra` segment should allow it.
