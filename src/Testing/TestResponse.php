<?php

declare(strict_types=1);

namespace Ions\Testing;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Fluent assertion wrapper around the Response returned by Kernel::handle().
 *
 * All assert* methods delegate to PHPUnit assertions (so failures are
 * reported normally by the test runner) and return $this for chaining.
 * The underlying Symfony response is available via the public
 * $baseResponse property as an escape hatch.
 */
class TestResponse
{
    public function __construct(
        public readonly Response $baseResponse,
    ) {
    }

    // -----------------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------------

    public function status(): int
    {
        return $this->baseResponse->getStatusCode();
    }

    public function content(): string
    {
        return (string) $this->baseResponse->getContent();
    }

    public function headers(): ResponseHeaderBag
    {
        return $this->baseResponse->headers;
    }

    /**
     * Decode the response body as JSON.
     *
     * With no arguments the full decoded structure is returned (or null when
     * the body is not valid JSON). With a dot-notation key, the value at that
     * path is returned (null when absent).
     */
    public function json(?string $key = null): mixed
    {
        $decoded = json_decode($this->content(), true);

        if ($key === null) {
            return $decoded;
        }

        if (!is_array($decoded)) {
            return null;
        }

        return Arr::get($decoded, $key);
    }

    // -----------------------------------------------------------------------
    // Status assertions
    // -----------------------------------------------------------------------

    public function assertStatus(int $expected): static
    {
        Assert::assertSame(
            $expected,
            $this->status(),
            sprintf('Expected response status code [%d] but received [%d].', $expected, $this->status())
        );

        return $this;
    }

    public function assertOk(): static
    {
        return $this->assertStatus(200);
    }

    public function assertCreated(): static
    {
        return $this->assertStatus(201);
    }

    public function assertNoContent(): static
    {
        $this->assertStatus(204);

        Assert::assertSame('', $this->content(), 'Expected an empty response body for a 204 No Content response.');

        return $this;
    }

    public function assertRedirect(?string $to = null): static
    {
        Assert::assertTrue(
            $this->baseResponse->isRedirect(),
            sprintf('Expected a redirect response but received status code [%d].', $this->status())
        );

        if ($to !== null) {
            Assert::assertSame(
                $to,
                $this->headers()->get('Location'),
                sprintf('Expected a redirect to [%s] but the Location header is [%s].', $to, (string) $this->headers()->get('Location'))
            );
        }

        return $this;
    }

    // -----------------------------------------------------------------------
    // Body assertions
    // -----------------------------------------------------------------------

    /** Assert the raw response body contains the given string. */
    public function assertSee(string $value): static
    {
        Assert::assertStringContainsString($value, $this->content(), 'Failed asserting that the response body contains the given string.');

        return $this;
    }

    /**
     * Assert the decoded JSON body contains the given array as a recursive
     * subset: every key in $subset must exist in the response with a matching
     * value; arrays are matched recursively (lists by index); extra keys in
     * the response are ignored.
     *
     * @param array<array-key, mixed> $subset
     */
    public function assertJson(array $subset): static
    {
        $actual = $this->json();

        Assert::assertIsArray($actual, 'The response body is not valid JSON: ' . $this->content());

        $this->assertJsonSubset($subset, $actual, '');

        return $this;
    }

    /** Assert the value at a dot-notation path in the JSON body is identical to $expected. */
    public function assertJsonPath(string $dotPath, mixed $expected): static
    {
        Assert::assertSame(
            $expected,
            $this->json($dotPath),
            sprintf('Failed asserting that the response JSON at path [%s] matches the expected value.', $dotPath)
        );

        return $this;
    }

    // -----------------------------------------------------------------------
    // Header assertions
    // -----------------------------------------------------------------------

    public function assertHeader(string $name, ?string $value = null): static
    {
        Assert::assertTrue(
            $this->headers()->has($name),
            sprintf('Response is missing the expected header [%s].', $name)
        );

        if ($value !== null) {
            Assert::assertSame(
                $value,
                $this->headers()->get($name),
                sprintf('Header [%s] is [%s]; expected [%s].', $name, (string) $this->headers()->get($name), $value)
            );
        }

        return $this;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Recursive subset comparison used by assertJson().
     *
     * @param array<array-key, mixed> $subset
     * @param array<array-key, mixed> $actual
     */
    private function assertJsonSubset(array $subset, array $actual, string $path): void
    {
        foreach ($subset as $key => $expected) {
            $fullPath = $path === '' ? (string) $key : $path . '.' . $key;

            Assert::assertArrayHasKey(
                $key,
                $actual,
                sprintf('Failed asserting that the response JSON has the key [%s].', $fullPath)
            );

            $value = $actual[$key];

            if (is_array($expected) && is_array($value)) {
                $this->assertJsonSubset($expected, $value, $fullPath);
                continue;
            }

            Assert::assertEquals(
                $expected,
                $value,
                sprintf('Failed asserting that the response JSON at [%s] matches the expected value.', $fullPath)
            );
        }
    }
}
