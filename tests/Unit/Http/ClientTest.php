<?php

declare(strict_types=1);

use Ions\Http\Client;
use Ions\Http\ClientResponse;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit tests for Ions\Http\Client + Ions\Http\ClientResponse against Symfony's
 * MockHttpClient. The capturing factory exposes the fully-processed request
 * (resolved URL with query string, normalized header lines, encoded body) so
 * option plumbing can be asserted without a network.
 */

/**
 * @param array{method: string, url: string, options: array<string, mixed>}|null $captured by-ref capture slot
 */
function httpCapturingClient(?array &$captured, MockResponse|callable|null $response = null): Client
{
    return new Client(new MockHttpClient(
        static function (string $method, string $url, array $options) use (&$captured, $response): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            if (is_callable($response)) {
                return $response($method, $url, $options);
            }

            return $response ?? new MockResponse('', ['http_code' => 200]);
        }
    ));
}

/**
 * @param array<string, mixed> $options
 */
function httpSentHeaders(array $options): string
{
    /** @var list<string> $headers */
    $headers = $options['headers'] ?? [];

    return implode("\n", $headers);
}

test('get() returns a ClientResponse exposing status, body and headers', function () {
    $client = new Client(new MockHttpClient(new MockResponse(
        '{"ok":true}',
        ['http_code' => 201, 'response_headers' => ['Content-Type' => 'application/json', 'X-Custom' => 'yes']]
    )));

    $response = $client->get('https://api.example.test/users');

    expect($response)->toBeInstanceOf(ClientResponse::class)
        ->and($response->status())->toBe(201)
        ->and($response->ok())->toBeTrue()
        ->and($response->body())->toBe('{"ok":true}')
        ->and($response->headers())->toHaveKey('x-custom')
        ->and($response->header('X-Custom'))->toBe('yes')
        ->and($response->header('content-type'))->toBe('application/json')
        ->and($response->header('X-Missing'))->toBeNull();
});

test('4xx and 5xx responses do not throw — ok() and status() report them', function () {
    $client = new Client(new MockHttpClient([
        new MockResponse('missing', ['http_code' => 404]),
        new MockResponse('boom', ['http_code' => 500]),
    ]));

    $notFound = $client->get('https://api.example.test/nope');
    $error = $client->get('https://api.example.test/boom');

    expect($notFound->status())->toBe(404)
        ->and($notFound->ok())->toBeFalse()
        ->and($notFound->body())->toBe('missing')
        ->and($error->status())->toBe(500)
        ->and($error->ok())->toBeFalse()
        ->and($error->body())->toBe('boom');
});

test('a discarded 4xx response never throws — fire-and-forget is safe', function () {
    $client = new Client(new MockHttpClient(new MockResponse('unprocessable', ['http_code' => 422])));

    // Return value intentionally discarded: the wrapped Symfony response is
    // destructed right here. Symfony's lazy initializer would throw a
    // ClientException from __destruct unless ClientResponse force-completes.
    $client->post('https://api.example.test/users', ['name' => '']);
    gc_collect_cycles();

    expect(true)->toBeTrue();
});

test('transport errors surface at the request call site, not at the first accessor', function () {
    $client = new Client(new MockHttpClient(new MockResponse('', ['error' => 'simulated network failure'])));

    $thrown = null;
    $response = null;

    try {
        $response = $client->get('https://api.example.test/down');
    } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface::class)
        ->and($thrown?->getMessage())->toBe('simulated network failure')
        ->and($response)->toBeNull();
});

test('json() decodes the body and supports dot-key access', function () {
    $client = new Client(new MockHttpClient(new MockResponse(
        '{"data":{"users":[{"name":"amr"}]},"total":1}'
    )));

    $response = $client->get('https://api.example.test/users');

    expect($response->json())->toBe(['data' => ['users' => [['name' => 'amr']]], 'total' => 1])
        ->and($response->json('data.users.0.name'))->toBe('amr')
        ->and($response->json('total'))->toBe(1)
        ->and($response->json('data.missing'))->toBeNull();
});

test('json() returns null for an empty body', function () {
    $client = new Client(new MockHttpClient(new MockResponse('', ['http_code' => 204])));

    expect($client->get('https://api.example.test/none')->json())->toBeNull();
});

test('get() appends query parameters to the URL', function () {
    $captured = null;
    httpCapturingClient($captured)->get('https://api.example.test/users', ['page' => 2, 'q' => 'a b']);

    expect($captured['url'])->toBe('https://api.example.test/users?page=2&q=a%20b')
        ->and($captured['method'])->toBe('GET');
});

test('withToken() sends an Authorization header on the request', function () {
    $captured = null;
    httpCapturingClient($captured)->withToken('secret-token')->get('https://api.example.test/me');

    expect(httpSentHeaders($captured['options']))->toContain('Authorization: Bearer secret-token');
});

test('withToken() supports a custom token type', function () {
    $captured = null;
    httpCapturingClient($captured)->withToken('abc', 'Basic')->get('https://api.example.test/me');

    expect(httpSentHeaders($captured['options']))->toContain('Authorization: Basic abc');
});

test('withHeaders() sends the given headers', function () {
    $captured = null;
    httpCapturingClient($captured)
        ->withHeaders(['X-Api-Key' => 'k1', 'Accept' => 'application/json'])
        ->get('https://api.example.test/users');

    expect(httpSentHeaders($captured['options']))->toContain('X-Api-Key: k1')
        ->and(httpSentHeaders($captured['options']))->toContain('Accept: application/json');
});

test('withHeaders() after withToken() overrides Authorization — later call wins, single header line', function () {
    $captured = null;
    httpCapturingClient($captured)
        ->withToken('first-token')
        ->withHeaders(['Authorization' => 'Bearer second-token'])
        ->get('https://api.example.test/me');

    /** @var list<string> $headers */
    $headers = $captured['options']['headers'];
    $authLines = array_values(array_filter(
        $headers,
        static fn (string $line): bool => str_starts_with(strtolower($line), 'authorization:')
    ));

    expect($authLines)->toBe(['Authorization: Bearer second-token']);
});

test('post() sends a form-encoded body', function () {
    $captured = null;
    httpCapturingClient($captured)->post('https://api.example.test/users', ['name' => 'ions', 'v' => 4]);

    expect($captured['method'])->toBe('POST')
        ->and($captured['options']['body'])->toBe('name=ions&v=4')
        ->and(httpSentHeaders($captured['options']))->toContain('Content-Type: application/x-www-form-urlencoded');
});

test('json() request method sends a JSON body with the JSON content type', function () {
    $captured = null;
    httpCapturingClient($captured)->json('https://api.example.test/users', ['name' => 'ions']);

    expect($captured['method'])->toBe('POST')
        ->and($captured['options']['body'])->toBe('{"name":"ions"}')
        ->and(httpSentHeaders($captured['options']))->toContain('Content-Type: application/json');
});

test('timeout() applies the timeout request option', function () {
    $captured = null;
    httpCapturingClient($captured)->timeout(5)->get('https://api.example.test/slow');

    expect($captured['options']['timeout'])->toBe(5.0);
});

test('baseUrl() resolves relative request URLs against the base', function () {
    $captured = null;
    httpCapturingClient($captured)->baseUrl('https://api.example.test/v1/')->get('users');

    expect($captured['url'])->toBe('https://api.example.test/v1/users');
});

test('an absolute request URL wins over baseUrl()', function () {
    $captured = null;
    httpCapturingClient($captured)
        ->baseUrl('https://api.example.test/v1/')
        ->get('https://other.example.test/health');

    expect($captured['url'])->toBe('https://other.example.test/health');
});

test('a leading-slash path drops the base URL path segment (RFC 3986 resolution)', function () {
    $captured = null;
    httpCapturingClient($captured)->baseUrl('https://api.example.test/v1/')->get('/users');

    expect($captured['url'])->toBe('https://api.example.test/users');
});

test('retry() retries retryable failures up to the given number of attempts', function () {
    $client = new Client(new MockHttpClient([
        new MockResponse('first try down', ['http_code' => 500]),
        new MockResponse('recovered', ['http_code' => 200]),
    ]));

    $response = $client->retry(2, 0)->get('https://api.example.test/flaky');

    expect($response->status())->toBe(200)
        ->and($response->body())->toBe('recovered');
});

test('without retry() a 500 comes back as-is on the first attempt', function () {
    $client = new Client(new MockHttpClient([
        new MockResponse('down', ['http_code' => 500]),
        new MockResponse('never reached', ['http_code' => 200]),
    ]));

    expect($client->get('https://api.example.test/flaky')->status())->toBe(500);
});

test('builder methods return a new instance and never mutate the original', function () {
    $captured = null;
    $base = httpCapturingClient($captured);

    $withAuth = $base->withToken('secret');

    expect($withAuth)->toBeInstanceOf(Client::class)
        ->and($withAuth)->not->toBe($base);

    // The original builder still sends no Authorization header.
    $base->get('https://api.example.test/anon');
    expect(httpSentHeaders($captured['options']))->not->toContain('Authorization:');
});
