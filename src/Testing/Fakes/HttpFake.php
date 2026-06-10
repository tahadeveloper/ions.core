<?php

declare(strict_types=1);

namespace Ions\Testing\Fakes;

use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Recording HTTP client fake for tests, installed via
 * {@see \Ions\Support\Http::fake()}.
 *
 * Implements Symfony's HttpClientInterface — the same surface the real 'http'
 * binding exposes — by delegating to Symfony's own MockHttpClient, so nothing
 * leaves the process. Every request is recorded AFTER Symfony processes it:
 * the URL is fully resolved (base_uri applied, query string appended) and the
 * options carry the normalized header lines, exactly what the wire would see.
 *
 * Faked responses (constructor argument):
 *  - null                  — every request gets 200 with an empty body.
 *  - assoc array           — URL pattern => response; patterns support '*'
 *                            wildcards and match the resolved URL with a
 *                            leading '*' implied (Laravel-style), so
 *                            'api.example.test/*' matches any scheme. Matching
 *                            is case-sensitive; append '*' when requests carry
 *                            query parameters. Values may be a MockResponse or
 *                            a plain string body (200). Unmatched requests get
 *                            200 with an empty body.
 *  - list                  — responses consumed in order (MockResponse or
 *                            string body); per MockHttpClient semantics the
 *                            queue must cover every request made.
 *  - callable              — MockHttpClient response factory:
 *                            fn (string $method, string $url, array $options).
 *
 * Every assertion returns $this so assertions chain.
 */
final class HttpFake implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    private MockHttpClient $mock;

    /**
     * @param callable|array<int|string, MockResponse|string>|null $responses
     */
    public function __construct(callable|array|null $responses = null)
    {
        $factory = $this->responseFactory($responses);

        $this->mock = new MockHttpClient(
            function (string $method, string $url, array $options) use ($factory): ResponseInterface {
                $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return $factory($method, $url, $options);
            }
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->mock->request($method, $url, $options);
    }

    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->mock->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        // The derived client shares the recording factory, so its traffic
        // still lands in THIS fake's recorded requests — assert on the fake
        // returned by Http::fake(). The clone starts with a clean slate
        // instead of a frozen snapshot of the traffic recorded so far.
        $clone = clone $this;
        $clone->mock = $this->mock->withOptions($options);
        $clone->requests = [];

        return $clone;
    }

    /**
     * All recorded requests in send order, each with the resolved URL and
     * Symfony's processed request options (headers as "Name: value" lines
     * under 'headers', encoded body under 'body').
     *
     * @return list<array{method: string, url: string, options: array<string, mixed>}>
     */
    public function sent(): array
    {
        return $this->requests;
    }

    /**
     * Assert at least one request matches. A string is a URL pattern matched
     * against the resolved URL ('*' wildcards supported, leading '*' implied
     * Laravel-style, case-sensitive — append '*' when requests carry query
     * parameters); a callable receives (string $method, string $url, array
     * $options) per recorded request and must return true for at least one.
     *
     * @param string|callable(string, string, array<string, mixed>): bool $urlOrFilter
     */
    public function assertSent(string|callable $urlOrFilter): static
    {
        if (is_string($urlOrFilter)) {
            $pattern = Str::start($urlOrFilter, '*');
            $filter = static fn (string $method, string $url): bool => Str::is($pattern, $url);
            $failure = sprintf('Expected an HTTP request matching url [%s] to be sent, but none matched.', $urlOrFilter);
        } else {
            $filter = $urlOrFilter;
            $failure = 'Expected an HTTP request matching the given filter, but none did.';
        }

        $matched = false;
        foreach ($this->requests as $request) {
            if ($filter($request['method'], $request['url'], $request['options']) === true) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, $failure . $this->sentSummary());

        return $this;
    }

    /**
     * Assert exactly $count requests were sent.
     */
    public function assertSentCount(int $count): static
    {
        Assert::assertCount(
            $count,
            $this->requests,
            sprintf('Expected exactly %d HTTP request(s), got %d.%s', $count, count($this->requests), $this->sentSummary())
        );

        return $this;
    }

    /**
     * Assert no requests were sent at all.
     */
    public function assertNothingSent(): static
    {
        Assert::assertCount(
            0,
            $this->requests,
            sprintf('Expected no HTTP requests to be sent, but %d were.%s', count($this->requests), $this->sentSummary())
        );

        return $this;
    }

    /**
     * Normalize the constructor argument into a MockHttpClient response factory.
     *
     * @param callable|array<int|string, MockResponse|string>|null $responses
     *
     * @return callable(string, string, array<string, mixed>): ResponseInterface
     */
    private function responseFactory(callable|array|null $responses): callable
    {
        if ($responses === null) {
            return static fn (): MockResponse => new MockResponse('', ['http_code' => 200]);
        }

        if (is_callable($responses)) {
            return $responses;
        }

        if (array_is_list($responses)) {
            $queue = array_map(self::normalizeResponse(...), $responses);

            return static function (string $method, string $url) use (&$queue): MockResponse {
                if ($queue === []) {
                    Assert::fail(sprintf(
                        'HttpFake response list exhausted: no response left for [%s %s].',
                        $method,
                        $url
                    ));
                }

                return array_shift($queue);
            };
        }

        /** @var array<string, MockResponse|string> $map */
        $map = $responses;

        return static function (string $method, string $url) use ($map): MockResponse {
            foreach ($map as $pattern => $response) {
                if (Str::is(Str::start($pattern, '*'), $url)) {
                    return self::normalizeResponse($response);
                }
            }

            return new MockResponse('', ['http_code' => 200]);
        };
    }

    private static function normalizeResponse(MockResponse|string $response): MockResponse
    {
        return is_string($response) ? new MockResponse($response, ['http_code' => 200]) : $response;
    }

    /**
     * Ions-worded summary of recorded traffic for assertion failures, to
     * distinguish "nothing matched" from "nothing sent at all".
     */
    private function sentSummary(): string
    {
        if ($this->requests === []) {
            return ' No HTTP requests were sent at all.';
        }

        $lines = array_map(
            static fn (array $request): string => sprintf('%s %s', $request['method'], $request['url']),
            $this->requests
        );

        return sprintf(' Sent: [%s].', implode(', ', $lines));
    }
}
