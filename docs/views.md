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

Namespaced names (`$this->view('@admin.users.index')`) bypass the controller
folder entirely.

## Legacy `render()` helper

The global `render($name, $parameters)` helper (echoes a template via
`Twig\Environment::display()`, initializing Localization first) predates the
`View` renderable and keeps working unchanged. Prefer returning `view(...)`
from actions: it goes through the middleware pipeline as a real `Response`
(headers, status codes, tests via `Kernel::handle()`), while `render()`
writes straight to the output buffer.
