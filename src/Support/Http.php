<?php

declare(strict_types=1);

namespace Ions\Support;

use Ions\Foundation\Kernel;
use Ions\Http\Client;
use Ions\Http\ClientResponse;
use Ions\Support\Concerns\ResolvesFake;
use Ions\Testing\Fakes\HttpFake;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin static facade over the container-bound Symfony HTTP client ('http',
 * bound lazily by {@see \Ions\Providers\HttpClientProvider}).
 *
 * Each call builds a fresh {@see Client} around the shared underlying client,
 * so per-request state (token, headers, timeout, retry, base URL) never leaks
 * between calls:
 *
 *     Http::withToken($token)->timeout(5)->retry(3)->get($url, ['page' => 2]);
 *     Http::json($url, ['name' => 'amr']);   // JSON POST
 *
 * In tests, {@see self::fake()} swaps the binding for a recording
 * {@see HttpFake} (Symfony MockHttpClient underneath — nothing leaves the
 * process) and the assertion passthroughs below resolve the installed fake.
 * The container is rebuilt per test boot, so an installed fake never leaks
 * into the next test.
 */
final class Http
{
    use ResolvesFake;

    /**
     * The container-bound Symfony client (the fake, once installed).
     */
    public static function client(): HttpClientInterface
    {
        /** @var HttpClientInterface $client */
        $client = Kernel::app()->get('http');

        return $client;
    }

    /**
     * @see Client::withToken()
     */
    public static function withToken(string $token, string $type = 'Bearer'): Client
    {
        return self::builder()->withToken($token, $type);
    }

    /**
     * @param array<string, string> $headers
     *
     * @see Client::withHeaders()
     */
    public static function withHeaders(array $headers): Client
    {
        return self::builder()->withHeaders($headers);
    }

    /**
     * @see Client::timeout()
     */
    public static function timeout(float $seconds): Client
    {
        return self::builder()->timeout($seconds);
    }

    /**
     * @see Client::retry()
     */
    public static function retry(int $times, int $delayMs = 1000): Client
    {
        return self::builder()->retry($times, $delayMs);
    }

    /**
     * @see Client::baseUrl()
     */
    public static function baseUrl(string $url): Client
    {
        return self::builder()->baseUrl($url);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @see Client::get()
     */
    public static function get(string $url, array $query = []): ClientResponse
    {
        return self::builder()->get($url, $query);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @see Client::post()
     */
    public static function post(string $url, array $data = []): ClientResponse
    {
        return self::builder()->post($url, $data);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @see Client::json()
     */
    public static function json(string $url, array $data = []): ClientResponse
    {
        return self::builder()->json($url, $data);
    }

    /**
     * Swap the 'http' binding for a recording fake and return it.
     *
     * @param callable|array<int|string, \Symfony\Component\HttpClient\Response\MockResponse|string>|null $responses
     *
     * @see HttpFake for the accepted response shapes
     */
    public static function fake(callable|array|null $responses = null): HttpFake
    {
        return self::installFake('http', new HttpFake($responses));
    }

    /**
     * @param string|callable(string, string, array<string, mixed>): bool $urlOrFilter
     *
     * @see HttpFake::assertSent()
     */
    public static function assertSent(string|callable $urlOrFilter): void
    {
        self::installedFake()->assertSent($urlOrFilter);
    }

    /**
     * @see HttpFake::assertSentCount()
     */
    public static function assertSentCount(int $count): void
    {
        self::installedFake()->assertSentCount($count);
    }

    /**
     * @see HttpFake::assertNothingSent()
     */
    public static function assertNothingSent(): void
    {
        self::installedFake()->assertNothingSent();
    }

    private static function builder(): Client
    {
        return new Client(self::client());
    }

    /**
     * The fake currently bound as 'http', or a hard failure pointing at the
     * missing Http::fake() call (without lazily building the Symfony client).
     */
    private static function installedFake(): HttpFake
    {
        return self::resolveInstalledFake('http', HttpFake::class, 'Http');
    }
}
