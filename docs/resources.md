# HTTP Resources, Form Requests & OpenAPI export

This document covers three related building blocks for shaping API responses
and validating API input, plus the `openapi:generate` route/spec export command.

## API Resources

`Ions\Http\Resource` is an abstract, `Responsable` class that shapes a single
model/array/`stdClass` into a typed JSON payload. Extend it and implement
`toArray(Request): array`:

```php
use Ions\Http\Resource;
use Ions\Support\Request;

class UserResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id'    => $this->get('id'),
            'name'  => $this->get('name'),
            'email' => $this->get('email'),
        ];
    }
}
```

`$this->get($key, $default)` reads from the wrapped resource whether it is an
array, an Eloquent model, a `stdClass` or any `ArrayAccess` object.

Return one from a controller action — the controller dispatcher calls
`toResponse()` automatically because `Resource` implements `Responsable`:

```php
public function show(Request $request): UserResource
{
    return UserResource::make(User::find($request->attributes->get('id')));
}
```

### Wrapping

By default the resource is nested under a `data` key, which is then placed inside
the framework's standard `Json::ok()` envelope:

```json
{ "status": "success", "data": { "data": { "id": 7, "name": "Ada" } } }
```

Disable the resource-level wrapping with `withoutWrapping()` (the `Json::ok()`
`data` envelope still applies) or change the key with `wrappedBy('user')`.

### Collections

`UserResource::collection($items)` returns an `Ions\Http\ResourceCollection`
that maps every item through the resource:

```php
return UserResource::collection(User::all());
```

`$items` may be an array, an Illuminate Collection or a
`LengthAwarePaginator`. When a paginator is given the payload additionally
carries `meta` and `links`:

```json
{
  "status": "success",
  "data": {
    "data": [ { "id": 1, "name": "A" } ],
    "meta":  { "current_page": 1, "last_page": 5, "per_page": 2, "total": 10 },
    "links": { "first": "...", "last": "...", "prev": null, "next": "..." }
  }
}
```

## Form Requests

`Ions\Http\FormRequest` is a typed, self-validating request object. Declare the
validation `rules()` and (optionally) an `authorize()` gate:

```php
use Ions\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // default
    }

    public function rules(): array
    {
        return [
            'name'  => 'required|string',
            'email' => 'required|email',
        ];
    }
}
```

Use it inside a controller via the explicit static helper — the simplest
ergonomic form:

```php
public function store(Request $request)
{
    $data = StoreUserRequest::validate($request);  // array<string,mixed>
    // ... persist $data
}
```

### Validation → 422 contract

`validated()` runs Illuminate validation against `request->all()`:

- On validation failure it throws Illuminate's `ValidationException`. The
  framework's `Ions\Http\ExceptionHandler` maps this to **HTTP 422** and, for
  API/JSON requests, renders the standard error envelope plus an `errors` bag:

  ```json
  { "status": "error", "message": "...", "code": 422,
    "errors": { "email": ["The email field is required."] } }
  ```

- When `authorize()` returns `false` it throws an `AccessDeniedHttpException`,
  which the handler renders as **HTTP 403**.

You do not need to catch anything in the controller: an uncaught
`ValidationException` bubbling out of an action is turned into a 422 by the
Kernel's exception pipeline.

## `openapi:generate` — route / OpenAPI export

The `openapi:generate` console command (registered on
`Ions\Console\Kernel`) builds an **OpenAPI 3.0** document from the application's
routes. It reuses the same route-capture logic the framework uses at runtime:
the `routes/{web,api}.php` (or `.yaml`) files plus attribute routes discovered
under `Http/` and `Http/Api`.

```bash
php bin/ions openapi:generate                 # writes openapi.json at the app root
php bin/ions openapi:generate --output=spec.json
php bin/ions openapi:generate --stdout        # print to stdout instead of writing
```

The emitted spec contains:

- `openapi: "3.0.3"` and an `info` block (`title` from `config('app.name')`,
  `version` from the project `CHANGELOG.md`, falling back to `4.0.0`).
- `paths` — one entry per route path, with an operation per HTTP method
  (`summary`, `tags` set to the route group, `parameters` derived from
  `{placeholder}` path segments).
- `security` — routes behind `AuthMiddleware` (any `/api/*` route not listed in
  `config('app.auth.public_paths')`) are marked with the `bearerAuth`
  requirement.
- `components.securitySchemes.bearerAuth` — `{ type: http, scheme: bearer }`.

This is a pragmatic, best-effort route inventory (methods, auth flags and path
parameters). Deeper request-body schema generation from FormRequest rules is a
possible future enhancement.
