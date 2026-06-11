# Views

Twig is the framework's template engine. One shared `Twig\Environment` is
built per process (the `view.env` container singleton, see
`Ions\View\ViewFactory`) from the `app.twig` config:

```php
// config/app.php
'twig' => [
    'source' => Path::views(''),      // template root (views/)
    'cache'  => Path::templates(''),  // compiled cache
    'paths'  => [                     // optional named namespaces (4.2)
        'admin' => 'views/admin',
        'mail'  => 'views/mail',
    ],
],
```

## Returning views from actions (4.2)

The `view()` helper builds an `Ions\View\View` — a lazy renderable (template
path + data), **not** a string. When an action (or a closure route) returns
one, the dispatcher renders it through the shared environment into a `200`
`text/html` response:

```php
public function index(Request $request): View
{
    return view('users.index', ['users' => $users]);
}
```

Name translation is pure (no filesystem checks — Twig reports missing
templates at render time):

| Call | Template |
|---|---|
| `view('users.index')` | `views/users/index.twig` |
| `view('users/index')` | `views/users/index.twig` |
| `view('emails/welcome.twig')` | `views/emails/welcome.twig` (extension kept) |
| `view('@admin.users.index')` | `@admin/users/index.twig` (namespace) |

`View::render(): string` is available when you need the markup directly
(e.g. to embed in a custom `Response`).

View returns are one branch of the shared return normalizer
(`Ions\Http\ResponseNormalizer`) — the full set of allowed action returns and
the controller lifecycle around them are documented in
[controllers.md](controllers.md).

## Namespaced view roots

`app.twig.paths` registers named namespaces on the loader (`'admin' =>
'views/admin'`). Relative directories resolve from the host root; absolute
paths (vendor packages shipping templates) are kept as-is. A missing
directory is skipped with a `view.log` warning — boot never dies for an
optional namespace. Details: [config.md → `app.twig.paths`](config.md#apptwigpaths).

## Controller-relative views

`BaseController::view($name, $data)` (web controllers only — `ApiController`
returns JSON and has no `view()`) prefixes the template with a folder derived
from the controller's position under `Http/Controllers`:

| Controller | Folder | `$this->view('index')` |
|---|---|---|
| `Http/Controllers/UsersController` | `users/` | `views/users/index.twig` |
| `Http/Controllers/Users/HomeController` | `users/` | `views/users/index.twig` |
| `Http/Controllers/Admin/UserReports/AnyController` | `admin/user-reports/` | `views/admin/user-reports/index.twig` |

The rule: nested controllers use their **directory path** (kebab-cased,
class name dropped); root-level controllers use their short name minus the
`Controller` suffix. Override with an explicit folder when the convention
does not fit:

```php
class LegacyController extends BaseController
{
    protected string $viewPath = 'custom/place'; // -> views/custom/place/...
}
```

> Acronym-heavy controller names kebab-case oddly (`API2\V1Controller` ->
> `a-p-i2/`) — set `$viewPath` explicitly for those controllers.

Namespaced names (`$this->view('@admin.users.index')`) bypass the controller
folder entirely.

## Pagination (4.3)

`paginate()` works out of the box — the paginator's page/path/query-string
resolvers are wired to the current request when the database boots:

```php
Route::get('/posts', function () {
    return view('posts.index', ['posts' => Post::query()->latest()->paginate(15)]);
});
```

```twig
{% for post in posts %} ... {% endfor %}
{{ pagination(posts) }}
```

The `pagination()` Twig function renders Previous/Next plus a windowed page
list (current ±2, first/last always shown, ellipses for the gaps) as clean,
Bootstrap-friendly markup — `nav > ul.pagination > li.page-item >
a/span.page-link`, with `active`/`disabled` states and `rel="prev"/"next"`.
It is usable unstyled. The current page comes from `?page=N`; **all other
query parameters are preserved** on every link. A single page of results
renders nothing; `simplePaginate()` renders Previous/Next only.

**Override the markup** by committing `views/pagination.twig` (at the root of
your Twig source). It receives:

- `paginator` — the `LengthAwarePaginator` (`currentPage`, `lastPage`,
  `total`, `url(page)`, `previousPageUrl`, `nextPageUrl`, …)
- `elements` — the computed window: a list of `{type: 'page', page, url,
  active}` and `{type: 'gap'}` entries.

For JSON APIs the paginator serializes itself (`data`, `current_page`,
`last_page`, `total`, page URLs) — combine with [API resources](resources.md).

The form-flow Twig functions `errors()` and `old()` are documented in
[forms.md](forms.md).

## Custom error pages (4.3)

In production (`APP_DEBUG` off) the exception handler renders HTML errors
through host templates when they exist, looked up through the shared Twig
environment in this order:

1. `views/errors/{status}.twig` — e.g. `errors/404.twig`, `errors/503.twig`
2. `views/errors/{4xx|5xx}.twig` — status-class fallback
3. the built-in minimal page — when neither template exists

Context passed to the template:

| Variable | Meaning |
| --- | --- |
| `status` | the HTTP status code (int) |
| `message` | the client-safe message — exactly what the built-in page would show, so a custom page can never leak more than before (generic exceptions stay generic in production) |
| `request_path` | the path that errored |

Guarantees:

- **Never throws.** A template failure (syntax error, runtime error, missing
  Twig config) logs a warning to `var/logs/view.log` and serves the built-in
  page — a broken error page is worse than a plain one.
- **Debug mode is unchanged** — the rich debug page always wins under
  `APP_DEBUG`.
- **API/JSON errors are unchanged** — templates only apply to the HTML path.

The skeleton ships a commented example at `views/errors/404.twig`.

## Legacy `render()` helper

The global `render($name, $parameters)` helper (echoes a template via
`Twig\Environment::display()`, initializing Localization first) predates the
`View` renderable and keeps working unchanged. Prefer returning `view(...)`
from actions: it goes through the middleware pipeline as a real `Response`
(headers, status codes, tests via `Kernel::handle()`), while `render()`
writes straight to the output buffer.
