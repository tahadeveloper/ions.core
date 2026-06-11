<?php

declare(strict_types=1);

namespace Ions\Foundation;

use Ions\Container\Container;
use Ions\Security\ArrayRevocationStore;
use Ions\Security\Jwt;
use Ions\Security\RevocationStore;

/**
 * Builds a {@see Jwt} instance from environment / config. Extracted verbatim
 * from Kernel::buildJwt() (12.7) — pure move, no behavior change.
 *
 * @internal Collaborator of {@see Kernel}; not part of the public API.
 */
final class JwtFactory
{
    /**
     * Build a Jwt instance from environment / config, or return null when the
     * signing key is absent or too short (< 32 bytes).
     *
     * Never throws — a missing or short key simply disables JWT signing.
     *
     * @param Container|null $app Container used to resolve an optional
     *                            'revocation_store' binding. When null, no
     *                            store is wired (matching the pre-boot path).
     * @return Jwt|null
     */
    public static function build(?Container $app = null): ?Jwt
    {
        $secret = (string) env('APP_KEY', '');
        if (strlen($secret) < 32) {
            return null;
        }

        // Resolve an optional RevocationStore from the container.
        // When a 'revocation_store' binding is present (e.g. a cache-backed
        // CacheRevocationStore registered by the app), it is used; otherwise
        // the in-memory ArrayRevocationStore is used as the default.
        // Note: ArrayRevocationStore only persists within a single request.
        // For cross-request revocation (e.g. logout that survives restart),
        // bind a persistent RevocationStore implementation as 'revocation_store'.
        $store = null;
        if ($app !== null) {
            if ($app->has('revocation_store')) {
                /** @var RevocationStore $store */
                $store = $app->get('revocation_store');
            } else {
                $store = new ArrayRevocationStore();
            }
        }

        try {
            // env('APP_NAME') reads as plain string at the env boundary, which
            // PHPStan widens to possibly-'' against Jwt's non-empty-string issuer/
            // audience. The 'ions' default is non-empty and Jwt tolerates the value
            // either way; ignored at the boundary rather than mutating the env read.
            return new Jwt(
                $secret,
                /** @phpstan-ignore argument.type */
                (string) env('APP_NAME', 'ions'),
                /** @phpstan-ignore argument.type */
                (string) env('APP_NAME', 'ions'),
                (int) config('app.jwt.ttl', 3600),
                (int) config('app.jwt.leeway', 0),
                $store,
                (int) config('app.jwt.refresh_ttl', Jwt::DEFAULT_REFRESH_TTL),
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
