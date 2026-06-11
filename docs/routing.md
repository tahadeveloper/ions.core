# Routing

Routes are registered in `routes/web.php` (for web requests) and `routes/api.php` (for requests whose first path segment is `api`). Both PHP and YAML file formats are supported; PHP files are preferred when both exist.

## Registering routes

```php
use Ions\Bundles\Route;

Route::get('/path', 'ControllerName@method');
Route::post('/path', 'ControllerName@method');
Route::put('/path/{id}', 'ControllerName@update');
Route::patch('/path/{id}', 'ControllerName@patch');
Route::delete('/path/{id}', 'ControllerName@destroy');
Route::options('/path', 'ControllerName@options');

// Match any HTTP method
Route::any('/path', 'ControllerName@method');

// Match specific methods
Route::match(['GET', 'POST'], '/path', 'ControllerName@method');
```

### Route parameters

Path segments wrapped in `{curly_braces}` become route parameters and are added to `$request->attributes`:

```php
Route::get('/users/{id}', 'UserController@show');
// $request->attributes->get('id') in the controller
```

### Closure routes

```php
Route::get('/ping', function (Request $request): Response {
    return Json::ok(['pong' => true]);
});
```

### Named routes

```php
// Fluent (4.3) — preferred:
Route::get('/about', 'PageController@about')->name('page.about');

// Legacy 4th argument — still supported:
Route::get('/about', 'PageController@about', [], 'page.about');
// signature: Route::get(string $path, string|Closure $controller, array $defaults = [], ?string $name = null, array $wheres = [])
```

`name()` renames the just-added route (the auto-generated random key is
replaced in the collection), so it chains freely with `middleware()` and
`where()` in any order. Inside a group declared with a name prefix (see
[Prefix and group](#prefix-and-group)) the prefix is prepended.

Generate URLs for named routes with the `route()` helper (4.3):

```php
route('page.about');                       // http://app.example/about
route('users.show', ['id' => 7]);          // placeholders filled
route('users.show', ['id' => 7, 'tab' => 'info']); // leftovers become ?tab=info
```

The absolute URL is built from `config('app.app_url')`. Name lookup checks
the shared collection (routes registered in `App\Booting`/tests) first, then
the `web` group, then `api`. `signedRoute()` (see [security.md](security.md))
and `redirect()->route()` ([forms.md](forms.md)) resolve identically — an
unknown name throws Symfony's `RouteNotFoundException`.

### Parameter constraints — `where()` (4.3)

Constrain placeholders with regexes (stored as Symfony route requirements —
enforced by both the live matcher and the compiled `route:cache` matcher, and
validated by `route()` URL generation). A non-matching value 404s:

```php
Route::get('/users/{id}', 'UserController@show')->where('id', '\d+');

Route::get('/posts/{year}/{slug}', 'PostController@show')
    ->where(['year' => '\d{4}', 'slug' => '[a-z-]+'])
    ->name('posts.show');
```

The legacy 5th argument (`$wheres`) keeps working; `where()` adds to it.

### Redirect and view routes (4.3)

```php
Route::redirect('/old', '/new');                    // 302
Route::redirect('/legacy', '/replacement', 301);    // custom status

Route::view('/welcome', 'pages.welcome', ['who' => 'world']);   // GET only
```

Both are backed by framework controllers (`Ions\Http\RedirectController`,
`Ions\Http\ViewController`) with the target/template carried as route
defaults — **fully compatible with `route:cache`** (no closures involved).
`Route::view()` uses the same template syntax as the `view()` helper (dots,
`@namespace` prefixes — see [views.md](views.md)); keep `$data` to plain
values when caching routes. Both return the fluent handle, so `->name()` /
`->where()` chain.

### Fallback routes (4.3)

```php
// routes/web.php — declare it anywhere in the file; it is appended LAST.
Route::fallback(fn () => view('errors.404-suggestions'));   // closure (not cacheable)
Route::fallback('FallbackController::show');                // cacheable string
```

`Route::fallback()` registers a GET catch-all (`/{fallback}` with a `.*`
requirement) for the route group whose file declared it (web or api). It is
deferred and appended at the **end** of the collection when the kernel builds
the group, so real routes — including attribute routes and the built-in
`/up` and `/cron/schedule` — always win. A Closure handler works live but
makes `route:cache` refuse, like any closure route; use a controller string
when you cache routes. Without a fallback, unmatched paths 404 as before.

### RESTful resource

```php
Route::resource('posts', 'PostController');
```

Registers seven routes: `index` (GET /posts), `create` (GET /posts/create), `store` (POST /posts), `show` (GET /posts/{id}), `edit` (GET /posts/{id}/edit), `update` (PUT /posts/{id}), `destroy` (DELETE /posts/{id}).

## Prefix and group

```php
// Inline closure (prefix is automatically popped after the closure)
Route::prefix('/admin')->group(function () {
    Route::get('/dashboard', 'AdminController@dashboard');
    Route::get('/users', 'AdminController@users');
});
```

`prefix()` pushes onto a stack; `group()` executes the closure then pops the prefix, so nesting works correctly.

### Group name + middleware prefixes (4.3)

A group builder also accepts a name prefix and middleware, applied to every
route registered inside the group:

```php
Route::prefix('/admin')->name('admin.')->middleware(['auth'])->group(function () {
    Route::get('/users', 'AdminController@users')->name('users');   // -> 'admin.users'
    Route::get('/posts', 'AdminController@posts', [], 'posts');     // -> 'admin.posts' (4th-arg names too)
});

route('admin.users');   // http://app.example/admin/users
```

- The **name prefix** is prepended to routes that are explicitly named inside
  the group (fluent `name()` or the 4th argument); unnamed routes keep their
  auto-generated keys.
- The **middleware** list is merged onto each route in the group; per-route
  `->middleware([...])` adds to it (it never replaces the group's).
- Groups nest: name prefixes concatenate (`outer.inner.deep`) and middleware
  lists merge.
- `prefix()->group()` without `name()`/`middleware()` behaves exactly as
  before.

## Per-route middleware

```php
Route::post('/login', 'AuthController@login')->middleware(['throttle']);
Route::get('/profile', 'UserController@profile')->middleware([
    \Ions\Http\Middleware\AuthMiddleware::class,
]);
```

`middleware(array $names)` accepts FQCN strings or alias strings. The Kernel resolves aliases using `app.middleware_aliases` (see [config.md](config.md)). Per-route middleware is appended to the group stack and runs closest to the controller.

## Attribute routing

Controllers annotated with `#[Route]` in `{app|src}/Http/` (web) or `{app|src}/Http/Api` (api) are auto-discovered via Symfony's `AttributeDirectoryLoader`:

```php
use Symfony\Component\Routing\Attribute\Route;

class ProductController
{
    #[Route('/products/{id}', methods: ['GET'])]
    public function show(Request $request): Response { ... }
}
```

Attribute routes are merged with file-based routes; file-based routes take precedence when names collide.

## Web vs API routing

| | Web | API |
|---|---|---|
| Route file | `routes/web.php` | `routes/api.php` |
| Controller namespace suffix | `Controllers\` | `Api\` |
| Default middleware | TrustedHost + SecurityHeaders + CORS + CSRF | TrustedHost + SecurityHeaders + CORS + Auth |
| Attribute scan path | `{app|src}/Http/` | `{app|src}/Http/Api` |
