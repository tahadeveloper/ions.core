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

    /** @var array<string, string> Headers sent with every subsequent request. */
    private array $defaultHeaders = [];

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
    }

    protected function tearDown(): void
    {
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
        return $this->call('GET', $uri, [], $headers);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('POST', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('PUT', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('PATCH', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('DELETE', $uri, $data, $headers);
    }

    /**
     * Send a JSON request: the data is encoded as the raw body and the
     * Content-Type / Accept headers are set to application/json.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public function json(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $headers);

        return $this->call($method, $uri, [], $headers, json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Low-level request builder: every verb helper funnels through here.
     * Stored default headers (withHeaders/withToken/actingAs) merge into the
     * request; per-call $headers win on conflict.
     *
     * @param array<string, mixed>  $parameters
     * @param array<string, string> $headers
     */
    public function call(string $method, string $uri, array $parameters = [], array $headers = [], ?string $content = null): TestResponse
    {
        $request = Request::create($uri, strtoupper($method), $parameters, [], [], [], $content);

        foreach (array_merge($this->defaultHeaders, $headers) as $name => $value) {
            $request->headers->set($name, $value);
        }

        return new TestResponse(Kernel::handle($request));
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
     * request (until flushHeaders() or the end of the test).
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): static
    {
        $this->defaultHeaders = array_merge($this->defaultHeaders, $headers);

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
