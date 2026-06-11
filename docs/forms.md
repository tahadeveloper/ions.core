# The web form flow (4.3)

The classic POST → validate → redirect-back → re-render cycle, end to end:
flash data, `old()` input, the `errors()` bag, the fluent `redirect()` API,
and FormRequest's content-negotiated failure handling.

```php
// routes/web.php
Route::get('/contact', fn () => view('contact'));
Route::post('/contact', function (Request $request) {
    $data = StoreContactRequest::validate($request);   // throws on failure

    return redirect('/contact/thanks')->with('status', 'Message sent.');
});
```

```twig
{# views/contact.twig #}
{% if errors().any %}<div class="alert">{{ errors().first() }}</div>{% endif %}

<form method="post" action="{{ appUrl('contact') }}">
    {{ ionToken('web') }}
    <input name="email" value="{{ old('email') }}">
    {% if errors().has('email') %}<small>{{ errors().first('email') }}</small>{% endif %}
    <button>Send</button>
</form>
```

When `StoreContactRequest` fails on this web route, the framework responds
with a **302 redirect back** (Referer, `/` fallback) and flashes the errors
and the request input. The next render sees them via `errors()` / `old()`;
the render after that does not — flash data is consumed.

## Flash data

Flash values live in the session for exactly one read — written during
request N, available until first read (normally request N+1's render), gone
afterwards. The mechanism is Symfony's session FlashBag, which behaves
identically on the `native` driver (production) and the `array` driver
(tests/CLI). Reads are memoized per request, so a template can call
`errors()`/`old()`/`flash($key)` repeatedly within one render and always see
the same value.

```php
flash('status', 'Profile saved.');   // set (two arguments)
flash('status');                     // read + consume (one argument)
```

Or fluently on a redirect (the flash is written immediately when the method
is called):

```php
return redirect('/profile')->with('status', 'Profile saved.');
return back()->with(['status' => 'Saved.', 'tab' => 'billing']);
```

## `old()` and `errors()`

Both helpers exist as PHP functions **and** Twig functions, and both are
always safe to call — no existence checks needed:

- `old('field', $default = null)` — input flashed by `withInput()`. Dot
  notation reaches nested input (`old('address.city')`).
- `errors()` — an `Ions\Http\ErrorBag`, empty when nothing was flashed:
  - `errors().any` / `errors().count` — anything to show?
  - `errors().has('email')` — field-level check
  - `errors().first('email')` / `errors().first()` — first message (field / overall)
  - `errors().get('email')` / `errors().all` — full lists
  - ArrayAccess: `$bag['email']` returns the field's message list.

### Sensitive input is never flashed

`withInput()` skips `password`, `password_confirmation` and
`current_password` by default. Override the list per host:

```php
// config/app.php
'forms' => ['dont_flash' => ['password', 'password_confirmation', 'current_password', 'ssn']],
```

## The fluent `redirect()` API

```php
redirect('/dashboard')                  // 302 to app_url + /dashboard
redirect('/moved', 301)                 // custom status
redirect()->to('/dashboard')            // same, via the builder
redirect()->route('users.show', ['id' => 7])   // named route (see docs/routing.md)
redirect()->back('/fallback')           // Referer or fallback
redirect()->away('https://ext.example') // external URL — never app_url-prefixed
back()                                  // shorthand for redirect()->back()
```

Each call returns an `Ions\Http\RedirectResponse` (a Symfony
`RedirectResponse` subclass — middleware and the response pipeline treat it
like any other response). Chain flash state onto it:

```php
return back()
    ->withErrors(['email' => 'That address is taken.'])  // array or ErrorBag
    ->withInput()                                        // current request input
    ->withInput(['email', 'name'])                       // ...or a subset
    ->with('status', 'Please fix the errors below.')
    ->withHeaders(['X-Reason' => 'validation']);
```

> **Security:** never pass user input (query parameters, form fields,
> headers) to `redirect()`/`to()` — validate it first or use named routes,
> otherwise you create an open redirect. `back()` already guards itself: it
> only honours same-origin Referers and falls back otherwise.

The legacy static `Ions\Bundles\Redirect` API (`Redirect::back()`,
`Redirect::away()`, …) is unchanged; prefer the fluent API in new code — it
returns responses instead of calling `send()`/`exit`.

## FormRequest failures are content-negotiated

A thrown `Illuminate\Validation\ValidationException` — from a FormRequest,
a manual `$validator->validate()`, anywhere — is rendered by the
ExceptionHandler as:

| Request | Response |
| --- | --- |
| `Accept: application/json` or first path segment `api` | **422 JSON** `{status, message, code, errors}` — unchanged from 4.2 |
| anything else (web/HTML) | **302 back** with errors + input flashed (new in 4.3) |

> **Upgrade note (4.3):** web (HTML) requests previously rendered the
> validation failure as a 422 HTML error page. They now redirect back with
> the errors and input flashed. API/JSON behavior is byte-identical.

See also: [controllers](controllers.md) · [views & pagination](views.md) ·
[session](session.md) · [routing & route()](routing.md).
