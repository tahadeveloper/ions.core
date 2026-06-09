<?php

namespace Ions\Bundles;

use Ions\Foundation\Singleton;
use Ions\Security\Jwt;
use Ions\Security\TokenException;

/**
 * @deprecated Use Ions\Security\Jwt directly.
 *
 * Thin backwards-compatible shim over the secure Ions\Security\Jwt.
 *
 * The legacy implementation generated an RSA keypair and used the PUBLIC key as
 * an HMAC secret, with NO expiry and NO user binding — so any token was valid
 * forever for everyone. That scheme has been removed entirely. Tokens are now
 * HMAC-SHA256 signed, expiring, and bound to a subject (user id).
 */
class AppKeys extends Singleton
{
    /**
     * Generate a 32-byte random application secret (hex-encoded) at var/app.key.
     *
     * No RSA, no key.pem. The file is created with 0600 permissions and is left
     * untouched if it already exists.
     */
    public static function createKey(): void
    {
        $keyFile = Path::var('app.key');
        if (!file_exists($keyFile)) {
            $hex = bin2hex(random_bytes(32));
            file_put_contents($keyFile, $hex);
            chmod($keyFile, 0600);
        }
    }

    private static function secret(): ?string
    {
        $envKey = env('APP_KEY');
        if (is_string($envKey) && $envKey !== '') {
            return $envKey;
        }
        $keyFile = Path::var('app.key');
        if (file_exists($keyFile)) {
            $contents = file_get_contents($keyFile);
            if (is_string($contents) && $contents !== '') {
                return trim($contents);
            }
        }
        return null;
    }

    private static function jwt(): ?Jwt
    {
        $secret = self::secret();
        if ($secret === null) {
            return null;
        }
        try {
            return new Jwt(
                $secret,
                (string) env('APP_NAME', 'ions'),
                (string) env('APP_NAME', 'ions'),
                (int) config('app.jwt.ttl', 3600),
            );
        } catch (TokenException) {
            return null;
        }
    }

    /**
     * @deprecated Use Ions\Security\Jwt directly. Tokens issued WITHOUT an explicit
     *             $subject are bound to the constant app id, NOT a user identity —
     *             callers must pass the authenticated user's id as $subject.
     *
     * @param string|null $subject The subject (user id) to bind the token to.
     */
    public static function createJWT(?string $subject = null): ?string
    {
        return self::jwt()?->issue($subject ?? (string) config('app.app_id', 'app'));
    }

    public static function validateJWT(string $token): array
    {
        $jwt = self::jwt();
        if ($jwt === null) {
            return ['success' => false, 'error' => 'No key'];
        }
        try {
            $jwt->verify($token);
            return ['success' => true];
        } catch (TokenException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
