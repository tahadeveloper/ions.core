<?php

declare(strict_types=1);

namespace Ions\Testing;

use Ions\Auth\Contracts\Authenticatable;
use Ions\Foundation\Kernel;
use Ions\Security\Jwt;
use Ions\Support\Request;
use PHPUnit\Framework\TestCase as BaseTestCase;
use RuntimeException;

/**
 * Base test case for HOST applications built on the Ions framework.
 *
 * Subclass it, point $basePath at your application root (the directory
 * containing config/ and routes/), and drive the app in-process:
 *
 *     class PingTest extends \Ions\Testing\TestCase
 *     {
 *         protected string $basePath = __DIR__ . '/..';
 *
 *         public function test_ping(): void
 *         {
 *             $this->get('/ping')->assertOk()->assertSee('pong');
 *         }
 *     }
 *
 * Each test boots a fresh kernel from $basePath (setUp) and resets all
 * framework static state afterwards (tearDown), so state never leaks
 * between tests. $_ENV / $_SERVER are snapshotted around each test because
 * Kernel::boot() loads the host's .env into them.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Absolute path to the host application root (the directory containing
     * config/, routes/, .env, …). Set it in your subclass — or override
     * basePath() when the path needs to be computed.
     */
    protected string $basePath = '';

    /** @var array<string, string> Headers (lowercased names) sent with every subsequent request. */
    private array $defaultHeaders = [];

    /** Guards call(): true only between a completed setUp() and tearDown(). */
    private bool $booted = false;

    /** @var array<array-key, mixed>|null */
    private ?array $envSnapshot = null;

    /** @var array<array-key, mixed>|null */
    private ?array $serverSnapshot = null;

    /** Override when the base path needs to be computed instead of assigned. */
    protected function basePath(): string
    {
        return $this->basePath;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $basePath = $this->basePath();
        if ($basePath === '' || !is_dir($basePath)) {
            throw new RuntimeException(sprintf(
                'Invalid application base path "%s" on %s. Set the protected $basePath property '
                . '(or override basePath()) to your host application root — the directory '
                . 'containing config/ and routes/.',
                $basePath,
                static::class
            ));
        }

        // Kernel::boot() loads the host .env into $_ENV/$_SERVER (Dotenv).
        // Snapshot both so nothing leaks into other tests in this process.
        $this->envSnapshot = $_ENV;
        $this->serverSnapshot = $_SERVER;

        Kernel::resetForTesting();
        Kernel::boot($basePath);

        $this->booted = true;
    }

    protected function tearDown(): void
    {
        $this->booted = false;

        Kernel::resetForTesting();
        $this->defaultHeaders = [];

        if ($this->envSnapshot !== null) {
            $_ENV = $this->envSnapshot;
            $this->envSnapshot = null;
        }
        if ($this->serverSnapshot !== null) {
            $_SERVER = $this->serverSnapshot;
            $this->serverSnapshot = null;
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // HTTP verbs
    // -----------------------------------------------------------------------

    /** @param array<string, string> $headers */
    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->call('GET', $uri, server: $this->transformHeadersToServerVars($headers));
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('POST', $uri, $data, server: $this->transformHeadersToServerVars($headers));
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('PUT', $uri, $data, server: $this->transformHeadersToServerVars($headers));
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('PATCH', $uri, $data, server: $this->transformHeadersToServerVars($headers));
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('DELETE', $uri, $data, server: $this->transformHeadersToServerVars($headers));
    }

    /**
     * Send a JSON request: the data is encoded as the raw body and the
     * Content-Type / Accept headers are passed as CONTENT_TYPE / HTTP_ACCEPT
     * server keys into Request::create, so the request's server bag and
     * header bag stay consistent.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public function json(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $headers = array_merge([
            'content-type' => 'application/json',
            'accept' => 'application/json',
        ], array_change_key_case($headers, CASE_LOWER));

        return $this->call(
            $method,
            $uri,
            server: $this->transformHeadersToServerVars($headers),
            content: json_encode($data, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Low-level request builder: every verb helper funnels through here.
     * Mirrors Laravel's positional signature (method, uri, parameters,
     * cookies, files, server, content). Stored default headers
     * (withHeaders/withToken/actingAs) are injected as HTTP_X / CONTENT_X
     * server keys; explicit $server entries win on conflict.
     *
     * @param array<string, mixed>  $parameters
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $files
     * @param array<string, mixed>  $server
     */
    public function call(string $method, string $uri, array $parameters = [], array $cookies = [], array $files = [], array $server = [], ?string $content = null): TestResponse
    {
        if (!$this->booted) {
            throw new RuntimeException('Kernel not booted — did you forget parent::setUp() in your setUp() override?');
        }

        $server = array_merge($this->transformHeadersToServerVars($this->defaultHeaders), $server);

        $request = Request::create($uri, strtoupper($method), $parameters, $cookies, $files, $server, $content);

        return new TestResponse(Kernel::handle($request));
    }

    /**
     * Translate HTTP header names into the server keys Request::create
     * expects: uppercased, dashes to underscores, HTTP_-prefixed except for
     * the CGI content headers (CONTENT_TYPE / CONTENT_LENGTH / CONTENT_MD5).
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function transformHeadersToServerVars(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $key = strtr(strtoupper($name), '-', '_');
            if (!in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true) && !str_starts_with($key, 'HTTP_')) {
                $key = 'HTTP_' . $key;
            }
            $server[$key] = $value;
        }

        return $server;
    }

    // -----------------------------------------------------------------------
    // Authentication & default headers
    // -----------------------------------------------------------------------

    /**
     * Authenticate subsequent requests as the given user by issuing a REAL
     * JWT through the kernel's configured signer and sending it as an
     * Authorization: Bearer header (exactly what AuthMiddleware verifies).
     *
     * Accepts an Authenticatable or a plain user id.
     *
     * @param array<non-empty-string, mixed> $claims Extra (non-reserved) JWT claims.
     */
    public function actingAs(Authenticatable|string|int $user, array $claims = []): static
    {
        $userId = $user instanceof Authenticatable
            ? (string) $user->getAuthIdentifier()
            : (string) $user;

        return $this->withToken($this->resolveJwt()->issue($userId, $claims));
    }

    /**
     * Merge headers into the stored defaults sent with every subsequent
     * request (until flushHeaders() or the end of the test). Header names
     * are lowercased on storage, so the same header set with different
     * casing (e.g. AUTHORIZATION vs Authorization) overrides instead of
     * duplicating.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->defaultHeaders[strtolower($name)] = $value;
        }

        return $this;
    }

    /** Send the given bearer token on every subsequent request. */
    public function withToken(string $token): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    /** Clear all stored default headers (including any actingAs/withToken token). */
    public function flushHeaders(): static
    {
        $this->defaultHeaders = [];

        return $this;
    }

    /**
     * Resolve the Jwt service the same way AuthMiddleware receives it:
     * the container 'jwt' binding when present, Kernel::buildJwt() otherwise.
     */
    private function resolveJwt(): Jwt
    {
        $app = Kernel::app();
        $jwt = $app->has('jwt') ? $app->get('jwt') : Kernel::buildJwt();

        if (!$jwt instanceof Jwt) {
            throw new RuntimeException(
                'actingAs() requires a configured JWT signer, but none is available. '
                . 'Set APP_KEY in the .env of your test application to a random secret of at '
                . 'least 32 bytes (e.g. a 64-character hex string: php -r "echo bin2hex(random_bytes(32));").'
            );
        }

        return $jwt;
    }
}
