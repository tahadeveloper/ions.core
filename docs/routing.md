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
Route::get('/about', 'PageController@about', [], 'page.about');
// signature: Route::get(string $path, string|Closure $controller, array $defaults = [], ?string $name = null, array $wheres = [])
```

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

## Per-route middleware

```php
Route::post('/login', 'AuthController@login')->middleware(['throttle']);
Route::get('/profile', 'UserController@profile')->middleware([
    \Ions\Http\Middleware\AuthMiddleware::class,
]);
```

`middleware(array $names)` accepts FQCN strings or alias strings. The Kernel resolves aliases using `app.middleware_aliases` (see [config.md](config.md)). Per-route middleware is appended to the group stack and runs closest to the controller.

## Attribute routing

Controllers annotated with `#[Route]` in `src/Http/` (web) or `app/Api/` (api) are auto-discovered via Symfony's `AttributeDirectoryLoader`:

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
| Attribute scan path | `src/Http/` | `app/Api/` |
