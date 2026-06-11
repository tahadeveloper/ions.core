# Controllers

The canonical reference for the controller lifecycle, dependency injection,
view returns, and per-controller middleware. Routing itself (how a URL maps
to `Controller::method`) is covered in [routing.md](routing.md); the request
pipeline around the controller in [lifecycle.md](lifecycle.md) and
[middleware.md](middleware.md).

Controllers are dispatched by `Ions\Http\Middleware\ControllerDispatcher`
(the pipeline terminal for string-controller routes). **Every hook below is
duck-typed by name** — so plain classes work as controllers; no base class or
interface is required. The legacy underscore hooks are detected via raw
`method_exists`; the four new hooks (`boot`, `middleware`, `beforeAction`,
`afterAction`) additionally require **public** visibility, so a protected or
private helper with one of those names is simply ignored.

## Lifecycle

```
Kernel::handle()
  └─ group middleware (web/api stack)
      └─ per-route middleware (->middleware([...]))
          └─ ControllerDispatcher
              __construct            ← container-built (constructor DI)
              _initState($request)   ← legacy, unchanged
              _loadInit($request)    ← legacy, unchanged
              _loadedState($request) ← legacy, unchanged
              boot(...)              ← NEW: method-injected (no route params)
              ┌─ controller middleware() — sub-pipeline ─┐
              │  beforeAction($request): ?Response       │ ← non-null short-circuits
              │  action(...)                             │ ← method-injected
              │  normalize: Response|Responsable|View    │
              │  afterAction($request, $response)        │ ← non-null replaces
              └───────────────────────────────────────────┘
              _endState($request)    ← legacy — ALWAYS runs (even on short-circuit)
```

Legacy hooks (`_initState → _loadInit → _loadedState → action → _endState`)
fire in the exact pre-9.3 order — a controller that uses none of the new
hooks behaves byte-identically (pinned by tests).

## Dependency injection

Controllers are instantiated via the container (`Kernel::app()->make()`), so
constructor dependencies resolve automatically. Zero-arg controllers are
unaffected. When subclassing `BaseController`/`ApiController`, call
`parent::__construct()`:

```php
class UsersController extends BaseController
{
    public function __construct(private readonly UserRepository $users)
    {
        parent::__construct();
    }
}
```

An unresolvable constructor dependency throws the container's
`BindingResolutionException`, rendered as a 500 — never swallowed.

### Action and boot() argument resolution

Action methods (and `boot()`) are invoked through
`Ions\Http\ActionArgumentResolver`. Per parameter, in order:

| # | Rule | Example |
|---|------|---------|
| 1 | `Request`-compatible type-hint → the current request | `Request $request` (Ions, Illuminate or Symfony hint) |
| 2 | Name matches a **route placeholder** (untyped or scalar-hinted) | `/users/{id}` → `int $id` receives `42` |
| 3 | **Eloquent `Model` subclass** hint whose name matches a route placeholder → the fetched record ([route model binding](#route-model-binding)) | `/widgets/{widget}` → `Widget $widget` receives the row |
| 4 | Other object type-hints → `app()->make()` | `UserRepository $users` |
| 5 | Untyped (or `mixed`) **first** parameter → the current request (legacy contract) | `function show($request)` |
| 6 | Declared default value | `string $slug = 'home'` |
| 7 | Nullable → `null` | `?Mailer $mailer` |
| 8 | Otherwise → clear exception (500) naming the parameter | |

Notes:

- Route values are matcher **strings**; `int`/`float` hints cast numeric
  values and `bool` hints cast `'1'/'0'/'true'/'false'` — anything else is
  passed through raw (the dispatcher runs under `strict_types`, so PHP raises
  the type error at call time).
- An object type-hint whose container resolution fails falls back to the
  parameter default, then `null` when nullable, otherwise the container error
  surfaces.
- Variadic parameters stop resolution.
- **Union/intersection-typed parameters are treated as unhinted** for rule
  purposes — placeholder-by-name (rule 2) can hit them, so type-hint `Request`
  explicitly if that's what you want; enum hints are not implicitly resolvable
  (the container cannot build an enum — supply a default or a nullable hint).
- Heads-up: **non-placeholder route defaults** (e.g. YAML `defaults:` entries
  whose keys don't start with `_`) are merged into the matched parameters and
  are therefore also injectable by name, exactly like placeholders.
- `boot()` is injected with services and the request but **not** route
  placeholders — those belong to the action.
- Closure routes get the same injection (placeholders + services +
  request); zero-param closures keep working.

```php
// routes/web.php:   Route::get('/posts/{id}/{slug}', PostsController::class . '::show');
public function show(Request $request, PostRepository $posts, int $id, string $slug = ''): Response
```

### Route model binding

Rule 3 in the table above: when an action (or closure route) parameter is
type-hinted with an **Eloquent `Model` subclass** and its **name matches a
route placeholder**, the resolver fetches the record instead of handing the
raw placeholder value through:

```php
// routes/web.php:   Route::get('/widgets/{widget}', WidgetsController::class . '::show');
public function show(Widget $widget): Response   // SELECT ... WHERE id = {widget} LIMIT 1
```

- **Lookup key:** the model's `getRouteKeyName()` — Eloquent's default is the
  primary key. Override it on the model to bind by another column:

  ```php
  class Post extends Model
  {
      public function getRouteKeyName(): string
      {
          return 'slug';   // /posts/{post} now resolves WHERE slug = ...
      }
  }
  ```

- **Miss → 404.** No matching record throws a `NotFoundHttpException`
  (`No query results for model [App\Models\Post] ...`) — the same 404 the
  `abort(404)` helper produces, rendered HTML/JSON by the standard exception
  handler.
- **Nullable miss → `null`.** A `?Widget $widget` parameter receives `null`
  on a miss instead of 404-ing — useful for "create or show" style actions.
- **Name must match.** A Model-hinted parameter whose name matches **no**
  placeholder falls through to rule 4 (container make) and receives a **new,
  empty model instance** — unchanged from earlier releases.
- **Requires the `db` engine.** Binding queries through Eloquent, so
  `config('app.database_engine')` must include `'db'`. Without a booted
  connection the resolver throws a clear `RuntimeException` ("Route model
  binding for [Class] requires the 'db' database engine"), rendered as a 500.
- **Global scopes apply.** Bindings run through `query()`, so global scopes
  filter the lookup — a soft-deleting model 404s trashed rows (there is no
  `withTrashed()` escape hatch in this phase).
- **Union types never bind.** Only a named `Model` subclass hint triggers
  binding; a union-typed parameter named after a placeholder receives the raw
  placeholder string per rule 2.
- **Scope cut:** the inline custom-key route syntax (`{user:slug}`) is not
  supported — `getRouteKeyName()` covers the conventional case.
- **Behavior note (pre-4.3):** before 4.3, a Model hint named after a
  placeholder hit the container rule and silently received a new **empty**
  model. The binding rule now fetches the real record (or 404s) — almost
  certainly what such signatures always intended.

## New hooks

### `boot()`

The "easy boot" hook — runs after the framework wiring (`_loadedState`),
before the action phase. Method-injected, so it can pull services without a
constructor:

```php
public function boot(SettingsService $settings): void
{
    $this->settings = $settings;
}
```

### `beforeAction(Request $request): ?Response`

Guard hook. Returning a `Response` short-circuits: the action and
`afterAction` are **skipped** (`_endState` still runs). Returning `null`
continues normally.

```php
public function beforeAction(Request $request): ?Response
{
    if (!$this->guard->check()) {
        return new Response('Forbidden', 403);
    }

    return null;
}
```

### `afterAction(Request $request, Response $response): ?Response`

Decoration hook. Receives the **normalized** response (after `View` /
`Responsable` conversion). Mutate it and return `null` to keep it, or return
a new `Response` to replace it:

```php
public function afterAction(Request $request, Response $response): ?Response
{
    $response->headers->set('X-Frame-Options', 'DENY');

    return null; // keep (now decorated) response
}
```

### `middleware(): array`

Per-controller middleware — aliases (`app.middleware_aliases`), FQCNs, or
`MiddlewareInterface` instances:

```php
public function middleware(): array
{
    return ['throttle', AuditMiddleware::class];
}
```

Returning a single bare entry (e.g. one `MiddlewareInterface` instance, not
wrapped in an array) also works. Entries are resolved **fail-closed** through
the same policy as per-route
middleware (see [middleware.md](middleware.md)): an unresolvable entry throws
(rendered as a 500) — a guarded action is never served unprotected. The
resolved middleware run as a sub-pipeline **inside** the dispatcher, wrapping
only the action phase (`beforeAction → action → afterAction`); construction
and the legacy hooks stay exactly where they always ran. Order around the
action: group middleware → route middleware → controller middleware.

## Returning responses

An action may return (see [views.md](views.md) for views):

| Return | Result |
|---|---|
| `Symfony\Component\HttpFoundation\Response` (or subclass) | used as-is |
| `Ions\Http\Responsable` | `toResponse($request)` |
| `Ions\View\View` (from `view()` / `$this->view()`) | rendered to a 200 HTML response |
| anything else (`null`, void, …) | the shared `Kernel::response()` the action may have written to (legacy) |

Normalization is shared by controller actions **and** closure routes
(`Ions\Http\ResponseNormalizer`) — closures can return `Responsable` and
`View` too.

## BaseController vs ApiController

| | `Foundation\BaseController` (web) | `Foundation\ApiController` (api) |
|---|---|---|
| Constructor | binds `$this->session`, boots the DB | binds `$this->request`/`$this->response`, boots the DB, parses `$this->inputs` |
| `_loadInit` | localization + Twig setup (`tJson` global) | localization only |
| Views | `$this->view()` controller-relative helper (`$viewPath` override) | n/a (return JSON / resources) |
| Helpers | — | `authUser()`, `authUserId()`, `display()`, `unauthorizedResponse()`, `notFoundResponse()` |

Both implement `callAction()` (actions run through it) and neither defines
the new hooks — add them per controller as needed.

## Backwards compatibility

- The legacy underscore hooks are untouched in name, order, and signature.
- Controllers not defining `boot` / `beforeAction` / `afterAction` /
  `middleware` are dispatched byte-identically to pre-9.3.
- Because the new hooks are duck-typed, an existing **public** controller
  method that happens to be named `boot()`, `middleware()`, `beforeAction()`,
  or `afterAction()` will now be treated as a lifecycle hook — rename such
  methods if they were plain actions. Protected/private methods with those
  names are not treated as hooks. Exception: when a route's **action** method
  is itself named `boot` (e.g. `Schedule::boot`), it is dispatched once as the
  action and the `boot()` hook is skipped — it never fires twice.
- Untyped first action parameters still receive the `Request` (positional
  legacy contract), unless their name matches a route placeholder.
