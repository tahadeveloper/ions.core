<?php

declare(strict_types=1);

namespace Ions\Session;

use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

/**
 * Config-driven session abstraction wrapping a Symfony Session.
 *
 * The storage driver is selected from config('session.driver'):
 *   - 'native'        -> NativeSessionStorage   (real PHP session, web requests)
 *   - 'array'/'mock'  -> MockArraySessionStorage (in-memory, tests/CLI)
 *
 * A token is lazily generated and stored under a reserved key so the session
 * can act as a single source of truth for CSRF/identity needs.
 */
final class SessionManager
{
    private const TOKEN_KEY = '_token';

    private Session $session;

    /**
     * @param array<string,mixed> $config Session config (typically config('session')).
     */
    public function __construct(private array $config = [], ?Session $session = null)
    {
        $this->session = $session ?? new Session($this->makeStorage());
    }

    /**
     * Resolve the storage driver from config.
     */
    private function makeStorage(): SessionStorageInterface
    {
        $driver = (string) ($this->config['driver'] ?? 'native');

        return match ($driver) {
            'array', 'mock' => new MockArraySessionStorage(),
            default => new NativeSessionStorage($this->nativeOptions()),
        };
    }

    /**
     * Build NativeSessionStorage options from config keys.
     *
     * Secure by default (4.1, D8-1): omitting the cookie_* keys yields
     * httponly + samesite=lax + secure cookies. Each default can be overridden
     * by setting the corresponding key explicitly in config/session.php.
     *
     * cookie_secure additionally accepts the string 'auto': the flag follows
     * the scheme of the current request (HTTPS -> secure). When no request is
     * available at session construction (CLI, early boot, workers before the
     * first request) 'auto' fails secure and the flag is true.
     *
     * Public so the option construction can be verified without starting a
     * native session (CLI/tests).
     *
     * @return array<string,mixed>
     */
    public function nativeOptions(): array
    {
        // D8-1 secure defaults — applied unless explicitly overridden below.
        $options = [
            'cookie_secure' => true,
            'cookie_httponly' => true,
            'cookie_samesite' => 'lax',
        ];

        if (isset($this->config['name'])) {
            $options['name'] = (string) $this->config['name'];
        }
        if (isset($this->config['lifetime'])) {
            $options['cookie_lifetime'] = (int) $this->config['lifetime'];
        }
        if (isset($this->config['cookie_secure'])) {
            $secure = $this->config['cookie_secure'];
            $options['cookie_secure'] = $secure === 'auto' ? $this->requestIsSecure() : (bool) $secure;
        }
        if (isset($this->config['cookie_httponly'])) {
            $options['cookie_httponly'] = (bool) $this->config['cookie_httponly'];
        }
        if (isset($this->config['cookie_samesite'])) {
            $options['cookie_samesite'] = (string) $this->config['cookie_samesite'];
        }

        return $options;
    }

    /**
     * Best-effort scheme detection for cookie_secure => 'auto'.
     *
     * Fails secure: when the kernel request is unavailable (CLI, tests,
     * pre-request worker boot) the cookie is marked secure.
     */
    private function requestIsSecure(): bool
    {
        try {
            return \Ions\Foundation\Kernel::request()->isSecure();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Start the session if it has not already been started.
     */
    public function start(): void
    {
        if (!$this->session->isStarted()) {
            $this->session->start();
        }
    }

    public function isStarted(): bool
    {
        return $this->session->isStarted();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->session->get($key, $default);
    }

    public function put(string $key, mixed $value): void
    {
        $this->session->set($key, $value);
    }

    public function has(string $key): bool
    {
        return $this->session->has($key);
    }

    public function forget(string $key): void
    {
        $this->session->remove($key);
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->session->all();
    }

    public function flush(): void
    {
        $this->session->clear();
    }

    /**
     * Add a flash message that survives exactly one further request.
     */
    public function flash(string $key, mixed $value): void
    {
        $this->session->getFlashBag()->add($key, $value);
    }

    /**
     * Retrieve (and consume) flash messages for the given key.
     *
     * @param array<int,mixed> $default
     * @return array<int,mixed>
     */
    public function getFlash(string $key, array $default = []): array
    {
        return $this->session->getFlashBag()->get($key, $default);
    }

    /**
     * Regenerate the session id, preserving the stored attributes.
     */
    public function regenerate(bool $destroy = false): bool
    {
        return $this->session->migrate($destroy);
    }

    /**
     * Lazily generate and return a per-session token.
     */
    public function token(): string
    {
        if (!$this->session->has(self::TOKEN_KEY)) {
            $this->session->set(self::TOKEN_KEY, bin2hex(random_bytes(20)));
        }

        return (string) $this->session->get(self::TOKEN_KEY);
    }

    public function getId(): string
    {
        return $this->session->getId();
    }

    public function getName(): string
    {
        return $this->session->getName();
    }

    public function save(): void
    {
        $this->session->save();
    }

    /**
     * Replace the inner Symfony session with a brand-new one (fresh storage,
     * fresh attribute/flash bags) built from the same config-driven driver.
     *
     * Used by Kernel::resetForRequest() between sequential requests in one
     * process (worker mode): the manager binding survives, but no session
     * data can bleed into the next request. A started session is saved first
     * so the native driver persists request N's data before being released.
     */
    public function renew(): void
    {
        if ($this->session->isStarted()) {
            $this->session->save();
        }

        $this->session = new Session($this->makeStorage());
    }

    /**
     * Expose the underlying Symfony session (e.g. to attach to a Request or
     * back a CSRF token storage).
     */
    public function getSession(): SessionInterface
    {
        return $this->session;
    }
}
