<?php

declare(strict_types=1);

namespace Ions\Http;

use Illuminate\Support\Arr;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Read-only wrapper around a Symfony HttpClient response, returned by every
 * {@see Client} request method.
 *
 * Throwing semantics: HTTP error statuses (4xx/5xx) NEVER throw — hosts are
 * expected to branch on ok()/status(). Every accessor reads the underlying
 * response with error-throwing disabled (getContent(false)/getHeaders(false)),
 * and the constructor force-completes the response so a discarded error
 * response never throws from Symfony's destructor either — fire-and-forget
 * (`Http::post($url, $data);` with the return value unused) is safe.
 * Transport failures (DNS, TLS, timeout) are a different story: because the
 * response is completed eagerly, they throw a
 * {@see \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface}
 * at the request call site (Client::get()/post()/json()).
 */
final class ClientResponse
{
    public function __construct(private readonly ResponseInterface $response)
    {
        // Force-complete the response. This (a) surfaces transport failures
        // here, at the request call site, and (b) disarms Symfony's lazy
        // initializer, whose __destruct would otherwise throw a ClientException
        // for a 4xx/5xx response that was never consumed — violating the
        // "HTTP error statuses never throw" contract on fire-and-forget calls.
        $this->response->getStatusCode();
    }

    /**
     * The HTTP status code (does not throw on 4xx/5xx).
     */
    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * True when the status is 2xx.
     */
    public function ok(): bool
    {
        $status = $this->status();

        return $status >= 200 && $status < 300;
    }

    /**
     * The raw response body. 4xx/5xx bodies are returned, not thrown.
     */
    public function body(): string
    {
        return $this->response->getContent(false);
    }

    /**
     * Decode the JSON body. With no key the full decoded value is returned;
     * a dot-notation key drills into it (missing paths yield null). An empty
     * body returns null; a non-JSON body throws a {@see \JsonException}.
     */
    public function json(?string $key = null): mixed
    {
        $body = $this->body();

        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if ($key === null) {
            return $decoded;
        }

        return is_array($decoded) ? Arr::get($decoded, $key) : null;
    }

    /**
     * All response headers, keyed by lowercase header name, each a list of
     * values (Symfony's shape). Does not throw on 4xx/5xx.
     *
     * @return array<string, list<string>>
     */
    public function headers(): array
    {
        return $this->response->getHeaders(false);
    }

    /**
     * The first value of a header (case-insensitive), or null when absent.
     */
    public function header(string $name): ?string
    {
        return $this->headers()[strtolower($name)][0] ?? null;
    }

    /**
     * Escape hatch to the underlying Symfony response.
     */
    public function toSymfony(): ResponseInterface
    {
        return $this->response;
    }
}
