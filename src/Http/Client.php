<?php

declare(strict_types=1);

namespace Ions\Http;

use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fluent, immutable HTTP request builder over Symfony HttpClient.
 *
 * Hosts normally reach it through the {@see \Ions\Support\Http} facade:
 *
 *     $response = Http::withToken($token)->timeout(5)->retry(3)
 *         ->get('https://api.example.test/users', ['page' => 2]);
 *
 * Every builder method (withToken, withHeaders, timeout, retry, baseUrl)
 * clones the builder, so a configured builder can
 * be reused safely. Request methods return an {@see ClientResponse}; HTTP
 * 4xx/5xx never throw (see ClientResponse for the full semantics).
 *
 * retry(): retryable failures (Symfony's default retry conditions — 423, 425,
 * 429, 500, 502, 503, 504 and transport errors) are re-attempted up to the
 * given number of extra tries by wrapping the client in Symfony's
 * RetryableHttpClient at request time, with exponential backoff starting at
 * $delayMs. The wrapping is a decorator, so it composes with Http::fake().
 */
final class Client
{
    /** @var array<string, string> */
    private array $headers = [];

    private ?float $timeout = null;

    private ?string $baseUrl = null;

    private int $retries = 0;

    private int $retryDelayMs = 1000;

    public function __construct(private readonly HttpClientInterface $client)
    {
    }

    /**
     * Send an Authorization header ("Bearer" by default).
     */
    public function withToken(string $token, string $type = 'Bearer'): self
    {
        return $this->withHeaders(['Authorization' => trim($type . ' ' . $token)]);
    }

    /**
     * Merge headers into the request (later calls win on conflicts).
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->headers = array_merge($clone->headers, $headers);

        return $clone;
    }

    /**
     * Idle timeout in seconds (maps to Symfony's 'timeout' option).
     */
    public function timeout(float $seconds): self
    {
        $clone = clone $this;
        $clone->timeout = $seconds;

        return $clone;
    }

    /**
     * Retry retryable failures up to $times extra attempts, backing off
     * exponentially from $delayMs milliseconds.
     */
    public function retry(int $times, int $delayMs = 1000): self
    {
        $clone = clone $this;
        $clone->retries = max(0, $times);
        $clone->retryDelayMs = max(0, $delayMs);

        return $clone;
    }

    /**
     * Resolve relative request URLs against this base. Note RFC 3986
     * semantics: end the base with '/' to keep its last path segment
     * ('https://api.test/v1/' + 'users' => '/v1/users').
     */
    public function baseUrl(string $url): self
    {
        $clone = clone $this;
        $clone->baseUrl = $url;

        return $clone;
    }

    /**
     * GET request; $query is appended to the URL.
     *
     * @param array<string, mixed> $query
     */
    public function get(string $url, array $query = []): ClientResponse
    {
        return $this->send('GET', $url, $query === [] ? [] : ['query' => $query]);
    }

    /**
     * POST request with a form-encoded (application/x-www-form-urlencoded) body.
     *
     * @param array<string, mixed> $data
     */
    public function post(string $url, array $data = []): ClientResponse
    {
        return $this->send('POST', $url, $data === [] ? [] : ['body' => $data]);
    }

    /**
     * POST request with a JSON body (Content-Type: application/json).
     *
     * @param array<string, mixed> $data
     */
    public function json(string $url, array $data = []): ClientResponse
    {
        return $this->send('POST', $url, ['json' => $data]);
    }

    /**
     * @param array<string, mixed> $options Symfony request options
     */
    private function send(string $method, string $url, array $options): ClientResponse
    {
        if ($this->headers !== []) {
            $options['headers'] = $this->headers;
        }

        if ($this->timeout !== null) {
            $options['timeout'] = $this->timeout;
        }

        if ($this->baseUrl !== null) {
            $options['base_uri'] = $this->baseUrl;
        }

        $client = $this->client;

        if ($this->retries > 0) {
            $client = new RetryableHttpClient(
                $client,
                new GenericRetryStrategy(delayMs: $this->retryDelayMs),
                $this->retries,
            );
        }

        return new ClientResponse($client->request($method, $url, $options));
    }
}
