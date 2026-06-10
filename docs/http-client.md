# HTTP client

Ions ships a thin, fluent HTTP client over `symfony/http-client`:
`Ions\Support\Http` (static facade) → `Ions\Http\Client` (immutable request
builder) → `Ions\Http\ClientResponse` (response wrapper). The underlying
Symfony client lives in the container as the `http` binding, registered by
`Ions\Providers\HttpClientProvider` as a **lazy** singleton — apps that never
call `Http` pay nothing for it.

## Usage

```php
use Ions\Support\Http;

// Simple GET — query array is appended to the URL.
$response = Http::get('https://api.example.test/users', ['page' => 2]);

// Fluent builder: token auth, timeout, retries.
$response = Http::withToken($apiToken)
    ->timeout(5)            // seconds
    ->retry(3)              // up to 3 extra attempts, exponential backoff
    ->get('https://api.example.test/users');

// POST — form-encoded body (application/x-www-form-urlencoded).
Http::post('https://api.example.test/users', ['name' => 'Amr']);

// POST — JSON body (Content-Type: application/json).
Http::json('https://api.example.test/users', ['name' => 'Amr']);

// Base URL for repeated calls against one API. RFC 3986 resolution applies:
// end the base with '/' to keep its last path segment.
$github = Http::baseUrl('https://api.github.com/')->withToken($token);
$user  = $github->get('users/ionzile');
$repos = $github->get('users/ionzile/repos');
```

### Builder methods

Every builder method returns a **new** `Ions\Http\Client` instance — a
configured builder can be stored and reused; later calls never mutate it.

| Method | Effect |
|---|---|
| `withToken(string $token, string $type = 'Bearer')` | `Authorization: <type> <token>` header |
| `withHeaders(array $headers)` | Merge request headers (later calls win) |
| `timeout(float $seconds)` | Idle timeout (Symfony `timeout` option) |
| `retry(int $times, int $delayMs = 1000)` | Retry retryable failures (Symfony defaults: 423, 425, 429, 500, 502, 503, 504 + transport errors) up to `$times` extra attempts, exponential backoff from `$delayMs` |
| `baseUrl(string $url)` | Resolve relative request URLs against the base |

Request methods (terminal — they send and return a `ClientResponse`):

| Method | Sends |
|---|---|
| `get(string $url, array $query = [])` | GET, `$query` appended to the URL |
| `post(string $url, array $data = [])` | POST, form-encoded body |
| `json(string $url, array $data = [])` | POST, JSON body |

`retry()` wraps the client in Symfony's `RetryableHttpClient` at request
time; it is a pure decorator, so it composes with `Http::fake()` in tests.

## The response wrapper

```php
$response = Http::get('https://api.example.test/users/7');

$response->status();           // 200
$response->ok();               // true for any 2xx
$response->body();             // raw body string
$response->json();             // full decoded JSON (assoc)
$response->json('data.name');  // dot-notation access, null when missing
$response->headers();          // ['content-type' => ['application/json'], ...]
$response->header('Content-Type');  // first value, case-insensitive, ?string
$response->toSymfony();        // underlying Symfony ResponseInterface
```

> **Note for Laravel users:** `ok()` here is true for **any 2xx** (Laravel's
> `ok()` is exactly 200; its `successful()` is the 2xx check), and `json()`
> **throws** a `\JsonException` on a non-JSON body (Laravel returns `null`).

## Throwing semantics (deliberate)

**HTTP error statuses never throw.** A 404 or a 500 comes back as a normal
`ClientResponse`; branch on `ok()` / `status()`:

```php
$response = Http::get($url);

if (!$response->ok()) {
    Logs::warning('upstream returned ' . $response->status());
    return null;
}
```

Internally the wrapper always reads the Symfony response with error-throwing
disabled (`getContent(false)` / `getHeaders(false)`), and force-completes the
response as soon as the request method returns. That makes fire-and-forget
safe: `Http::post($url, $data);` with the return value unused never throws for
a 4xx/5xx — not even from Symfony's destructor at garbage collection.

Two things **do** throw:

- **Transport failures** — DNS, TLS, connection refused, timeout. Because the
  response is completed eagerly, a
  `Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface` is
  thrown **at the request call site** (`Http::get()` / `post()` / `json()`),
  not later at the first accessor.
- **`json()` on a non-JSON body** — throws `\JsonException`. An empty body
  returns `null` instead.

## Faking in tests — `Http::fake()`

`Http::fake()` swaps the container's `http` binding for
`Ions\Testing\Fakes\HttpFake` — a recorder built on Symfony's own
`MockHttpClient`, so nothing leaves the process. It records every request
*after* Symfony processes it: URLs are fully resolved (base URL applied,
query string appended) and options carry the normalized header lines.

```php
use Ions\Support\Http;
use Symfony\Component\HttpClient\Response\MockResponse;

// Every request answers 200 with an empty body.
$fake = Http::fake();

// Or fake responses per URL pattern ('*' wildcards, matched against the
// resolved URL). A leading '*' is implied, Laravel-style, so scheme-less
// patterns like 'api.example.test/*' work. Matching is case-sensitive;
// append '*' when requests carry query parameters (the resolved URL
// includes the query string). Values: MockResponse, or a plain string
// body (200).
Http::fake([
    'api.example.test/users*'         => new MockResponse('{"id":7}', ['http_code' => 201]),
    'https://api.example.test/ping'   => 'pong',
]);

// Or a sequential list consumed in order, or a MockHttpClient factory:
Http::fake([new MockResponse('first'), 'second']);
Http::fake(fn (string $method, string $url) => new MockResponse('hi'));
```

Unmatched requests against a pattern map get 200 with an empty body; an
exhausted sequential list fails the test with a message naming the extra
request.

### Assertions

Available statically on `Http` (requires the fake — a missing `Http::fake()`
call throws a `RuntimeException` saying so) and on the fake instance, where
they chain:

| Assertion | Verifies |
|---|---|
| `assertSent(string\|callable $urlOrFilter)` | At least one request matches; a string is a URL pattern (`*` wildcards, leading `*` implied, case-sensitive), a callable receives `(string $method, string $url, array $options)` |
| `assertSentCount(int $count)` | Exact number of requests sent |
| `assertNothingSent()` | No requests were sent at all |

`$fake->sent()` returns every recorded request in order, each as
`['method' => ..., 'url' => ..., 'options' => ...]`.

```php
$fake = Http::fake();

Http::withToken('secret')->json('https://api.example.test/users', ['name' => 'Amr']);

Http::assertSent('https://api.example.test/*');
$fake->assertSent(fn (string $method, string $url, array $options) =>
    $method === 'POST'
    && str_contains(implode("\n", $options['headers']), 'Authorization: Bearer secret')
)->assertSentCount(1);
```

Because every test boots a fresh container, an installed fake never leaks
into the next test — there is nothing to tear down. See
[docs/testing.md](testing.md) for the full fakes chapter.
