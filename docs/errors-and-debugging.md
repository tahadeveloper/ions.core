# Errors & debugging (Phase 14)

The framework ships a cohesive, first-party developer UI for the three surfaces
you hit while debugging a host app — the **interactive debug page**, the
**debug toolbar**, and the **branded production error pages** — all built on a
shared `Ions\Http\Ui` design system. Everything is **self-contained** (inline
CSS/JS, no external assets, no CDN), introduces **no new Composer dependencies**,
and the two developer surfaces are **strictly dev-only** (gated on `APP_DEBUG`).

> The redaction guarantee carries through all of it: nothing on the debug page
> or toolbar prints a raw secret. See [Redaction](#redaction) below.

## The debug page

When an uncaught exception reaches `Ions\Http\ExceptionHandler` **and
`APP_DEBUG` is truthy**, web (HTML) requests render the interactive debug page
(`Ions\Http\DebugPage`). API/JSON requests get a JSON error body instead — the
page is never produced for them.

It is a Whoops-class experience, rendered entirely server-side:

- **Header** — the status code, exception class, message and the throw location,
  with an *open in editor* link (see [`app.debug.editor`](#appdebugeditor)).
- **Frames** — a clickable two-pane stack: the frame list on the left, the
  highlighted source window on the right. The throw frame is selected first;
  application frames are visually distinguished from vendor frames. Source is
  syntax-highlighted **server-side** via `token_get_all()` (no JS highlighter,
  no network) by `Ions\Http\Ui\SourceHighlighter`, with the error line marked.
- **Tabs** — **Request / Headers / Params / Cookies / Session / Context**, each a
  redacted key/value table.
- **Actions** — **Copy as Markdown** (a paste-ready report of the exception,
  location and trace) and the per-frame **open in editor** link.

The interactivity (frame switching, tab switching, copy) is a tiny inlined
vanilla script. With JavaScript disabled the page still works: the throw-frame
excerpt, the full trace table and every tab table are present in the
server-rendered markup — the script only toggles visibility. The renderer is
**fail-closed**: an unreadable source file or odd edge data degrades a section
rather than throwing inside the error handler.

## Redaction

Every value shown on the debug page **and** the toolbar runs through the same
recursive key redactor used by log redaction (`Ions\Bundles\RedactionProcessor`),
widened for the request context. A value is masked when its key matches
(case-insensitive, substring): **password / passwd / token / secret /
authorization / api_key** — plus, on the debug page, **cookie / php-auth\* /
session / csrf**. Redaction is recursive, so a nested `user[password]` is masked
too.

Concretely:

- **Headers** — `Authorization`, `Cookie`/`Set-Cookie` and the decoded
  `php-auth-*` values are masked; the rest of the header set is visible.
- **Params** — query + body params with a sensitive key (e.g. `password`,
  `token`, `api_key`) are masked.
- **Cookies** — **every cookie value is masked** (keys stay visible). Cookies are
  overwhelmingly credentials — session ids, remember-me, CSRF — so a blanket mask
  is applied rather than relying on a cookie being named recognisably.
- **Session** — sensitive keys (including the CSRF token key) are masked.

The debug page never prints a raw `Authorization`, `Cookie`, decoded basic-auth
credential, or session/CSRF token. It is **dev-only** — it is only ever produced
when `APP_DEBUG` is truthy.

## The debug toolbar

`Ions\Http\Middleware\DebugToolbarMiddleware` injects a fixed-position footer bar
before the closing `</body>` of an HTML response. It is attached to the **web**
middleware stack **only when `APP_DEBUG` is truthy at stack-build time**, so a
production request never even constructs it — zero cost.

The collapsed strip shows request wall time, the matched route/target, query
count + total ms, peak memory, and the PHP + Ions versions. Each labelled segment
is a button that **expands a detail panel** above the strip:

- **queries** — the captured SQL list with per-query time and a binding *count*
  (binding **values are never shown**, to avoid leaking data on a shared screen).
  Every SQL string is HTML-escaped.
- **request** — method, path, route, status, content-type.
- **timing** — wall time, peak memory, runtime versions.

Expand/collapse state is ephemeral (a tiny inlined script — no cookies, no
localStorage). The styles and script are inlined **once** and **scoped under
`#ions-debug-toolbar`**, so they never clobber the host page's CSS.

### Capturing SQL

The queries panel needs the query log enabled. Set `database.query_log` truthy
**and** enable the connection's query log (e.g. `DB::connection()->enableQueryLog()`
in a debug bootstrap). With capture off the panel shows a hint instead of a count;
the strip segment reads `log off`.

### Query cap

To keep a pathological N+1 page from injecting thousands of rows into the
response, the queries panel renders **at most the first 100 queries**, then a
`… N more queries` marker line. The strip header count and total time still
reflect **all** queries — only the rendered row list is capped.

### Injection safety

The toolbar must never break a response. It only touches **string** bodies that
contain `</body>` and whose `Content-Type` is `text/html` (or unset);
JSON/redirect/streamed/binary responses pass through byte-identical. After
injecting it removes the now-stale `Content-Length` header, and the whole
operation is wrapped in a `try/catch` so any failure returns the original
response untouched.

### Disabling it

```php
// config/app.php — escape hatch while debugging in a debug build
'debug_toolbar' => false,
```

It defaults to on (under `APP_DEBUG`); set it to `false` to suppress the bar
without turning off `APP_DEBUG`.

## Production error pages

When `APP_DEBUG` is **off**, `ExceptionHandler` renders a **branded, JS-free,
inline-CSS** error page (`Ions\Http\Ui\ErrorPage`) built from the shared design
system — a big status, the client-safe message and a home link. Built-in pages
exist for **400, 401, 403, 404, 405, 419, 429, 500 and 503**.

The client-safe message rules are unchanged: an `HttpException`'s message is
deliberate and client-facing; a generic exception **never** leaks its message in
production (you get the status text instead).

### Overriding with host templates

A host may override any error page by shipping a Twig template under
`views/errors/`. The lookup chain is:

1. `views/errors/{status}.twig` — e.g. `views/errors/404.twig`;
2. `views/errors/{4xx|5xx}.twig` — a status-class fallback, e.g.
   `views/errors/5xx.twig`.

The first that exists wins; otherwise the built-in branded page renders. Each
template receives:

- `status` — the integer HTTP status;
- `message` — the **same** client-safe message the built-in page would show (no
  new information is exposed).

```twig
{# views/errors/404.twig #}
<!DOCTYPE html>
<html lang="en">
  <head><meta charset="utf-8"><title>{{ status }} — Not found</title></head>
  <body>
    <h1>{{ status }}</h1>
    <p>{{ message }}</p>
    <a href="/">Go home</a>
  </body>
</html>
```

Template rendering is **fail-closed**: a missing Twig config, a template syntax
error or a runtime error is logged and answered with the built-in page rather
than surfacing a second exception inside the error handler.

## Config keys

### `app.debug.editor`

Controls the per-frame *open in editor* link on the debug page
(`Ions\Http\Ui\EditorLink`). Set it to one of the known editor keys, or to a
custom format string containing the `{file}` / `{line}` placeholders. **Unset /
`false` / empty ⇒ no editor links are rendered** (links are opt-in, never
fabricated).

| Value                              | Result                                         |
|------------------------------------|------------------------------------------------|
| `vscode`                           | `vscode://file/{file}:{line}`                  |
| `phpstorm`                         | `phpstorm://open?file={file}&line={line}`      |
| `sublime`                          | `subl://open?url=file://{file}&line={line}`    |
| `textmate`                         | `txmt://open?url=file://{file}&line={line}`    |
| `idea`                             | `idea://open?file={file}&line={line}`          |
| `editor://{file}:{line}` (example) | used verbatim — any string with `{file}`/`{line}` is a custom template |

```php
// config/app.php
'debug' => [
    'editor' => env('IONS_EDITOR', 'phpstorm'),
],
```

The file path is `rawurlencode`d per segment (spaces become `%20`) so the URI is
always valid.

### `app.debug_toolbar`

Master switch for the [debug toolbar](#the-debug-toolbar). Defaults to **on**
under `APP_DEBUG`; set `'debug_toolbar' => false` to suppress the bar while
keeping debug mode on. It has no effect in production (the toolbar is never built
when `APP_DEBUG` is off).
