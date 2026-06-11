<?php

declare(strict_types=1);

namespace Ions\Foundation;

use Ions\Bundles\Path;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Maintenance mode (10.8): `ions down` writes var/maintenance.php (a
 * var_export'ed array: time, retry, secret hash) and Kernel::handle() gates
 * every request on it BEFORE routing.
 *
 * Gate outcomes:
 *  - flag file absent           -> pass (a single file_exists() per request —
 *                                  the only cost when the app is live)
 *  - request path == /{secret}  -> 302 to '/' + bypass cookie (sha256 of the
 *                                  secret; never the secret itself)
 *  - valid bypass cookie        -> pass
 *  - path == /up (health on)    -> pass — the liveness probe stays reachable
 *                                  so ops can monitor the box DURING
 *                                  maintenance (deliberate divergence from
 *                                  Laravel, which exempts nothing)
 *  - otherwise                  -> HttpException 503 (+ Retry-After when
 *                                  configured), rendered by the
 *                                  ExceptionHandler — errors/503.twig theming
 *                                  and the API JSON shape both apply.
 */
final class MaintenanceMode
{
    /** Bypass cookie name — its value is the sha256 hash of the secret. */
    public const COOKIE = 'ions_maintenance';

    /** Bypass cookie lifetime in seconds (12 hours, Laravel parity). */
    private const COOKIE_TTL = 43200;

    /** The flag file path: var/maintenance.php in the host app. */
    public static function path(): string
    {
        return Path::var('maintenance.php');
    }

    public static function active(): bool
    {
        return file_exists(self::path());
    }

    /**
     * Write the flag file. Returns the stored payload.
     *
     * @return array{time: int, retry: int|null, secret: string|null}
     */
    public static function activate(?string $secret = null, ?int $retry = null): array
    {
        $payload = [
            'time' => time(),
            'retry' => $retry,
            // Only the hash is stored — the file never holds the raw secret.
            'secret' => $secret !== null && $secret !== '' ? hash('sha256', $secret) : null,
        ];

        file_put_contents(
            self::path(),
            '<?php return ' . var_export($payload, true) . ';' . PHP_EOL,
            LOCK_EX
        );

        return $payload;
    }

    /** Remove the flag file. Returns false when the app was not down. */
    public static function deactivate(): bool
    {
        return self::active() && unlink(self::path());
    }

    /**
     * Gate a request while maintenance is active.
     *
     * @return Response|null A redirect Response for the secret-bypass URL,
     *                       or null when the request may proceed.
     * @throws HttpException 503 for everyone else.
     */
    public static function gate(Request $request): ?Response
    {
        $data = self::data();
        $secretHash = isset($data['secret']) && is_string($data['secret']) ? $data['secret'] : null;
        $path = $request->getPathInfo();

        // Liveness probe exemption — see class docblock.
        if ($path === '/up' && config('app.health.enabled', true) !== false) {
            return null;
        }

        if ($secretHash !== null) {
            // The bypass token is HMAC-bound to THIS down cycle (its
            // activation time), so a cookie minted before `up` never replays
            // into a later maintenance window even when the secret is reused.
            $downTime = isset($data['time']) && is_int($data['time']) ? $data['time'] : 0;
            $bypassToken = hash_hmac('sha256', (string) $downTime, $secretHash);

            // Visiting /{secret} sets the bypass cookie and bounces home
            // (base-path aware for APP_FOLDER subfolder deployments).
            $candidate = hash('sha256', rawurldecode(ltrim($path, '/')));
            if (hash_equals($secretHash, $candidate)) {
                $base = $request->getBasePath();
                $redirect = new RedirectResponse($base !== '' ? $base . '/' : '/', 302);
                $redirect->headers->setCookie(Cookie::create(
                    self::COOKIE,
                    $bypassToken,
                    time() + self::COOKIE_TTL,
                    '/',
                    null,
                    $request->isSecure() ?: null,
                    true // httpOnly
                ));

                return $redirect;
            }

            // A request carrying the valid bypass cookie passes through.
            $cookie = (string) $request->cookies->get(self::COOKIE, '');
            if ($cookie !== '' && hash_equals($bypassToken, $cookie)) {
                return null;
            }
        }

        $headers = [];
        if (isset($data['retry']) && is_numeric($data['retry'])) {
            $headers['Retry-After'] = (string) (int) $data['retry'];
        }

        throw new HttpException(503, 'Service Unavailable', null, $headers);
    }

    /**
     * Read the flag file payload; tolerant of a corrupt/foreign file (a
     * broken payload still keeps the app down, just without retry/secret).
     *
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        try {
            $data = include self::path();
        } catch (\Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }
}
