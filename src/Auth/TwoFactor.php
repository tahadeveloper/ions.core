<?php

declare(strict_types=1);

namespace Ions\Auth;

use InvalidArgumentException;

/**
 * RFC 6238 time-based one-time password (TOTP) building blocks.
 *
 * Dependency-free: base32 (RFC 4648) and HMAC-based code derivation are
 * implemented here against the official RFC test vectors (see the unit suite).
 * Every method is static and pure — the class holds no state, which keeps it
 * trivially testable. Stateful replay protection lives in
 * {@see TwoFactorReplayStore}.
 *
 * The framework provides the verifier and helpers; the host wires persistence
 * (encrypt the secret at rest with {@see \Ions\Security\Encrypter}), renders
 * the {@see otpauthUri()} as a QR code, and gates the login flow. See
 * `docs/two-factor.md`.
 */
final class TwoFactor
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Alphabet for human-typed recovery codes (lowercase, no ambiguous chars). */
    private const RECOVERY_ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789';

    /**
     * Generate a cryptographically random shared secret as RFC 4648 base32.
     *
     * Returned uppercase without padding — the form Google Authenticator and
     * compatible apps expect.
     */
    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 1) {
            throw new InvalidArgumentException('Secret length must be at least 1 byte.');
        }

        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * Compute the TOTP value for a moment in time.
     *
     * @param string   $secret    base32-encoded shared secret
     * @param int|null $timestamp Unix seconds (defaults to now)
     * @param int      $period    time step in seconds
     * @param int      $digits    code length
     * @param string   $algo      'sha1' | 'sha256' | 'sha512'
     */
    public static function code(
        string $secret,
        ?int $timestamp = null,
        int $period = 30,
        int $digits = 6,
        string $algo = 'sha1',
    ): string {
        if ($period < 1) {
            throw new InvalidArgumentException('Period must be a positive number of seconds.');
        }
        if ($digits < 1 || $digits > 10) {
            throw new InvalidArgumentException('Digits must be between 1 and 10.');
        }

        $timestamp ??= time();
        $counter = intdiv($timestamp, $period);

        return self::hotp(self::base32Decode($secret), $counter, $digits, self::normaliseAlgo($algo));
    }

    /**
     * Verify a submitted code against the secret, allowing ±$window steps of
     * clock drift.
     *
     * Timing-safe: every candidate step is compared with {@see hash_equals()}
     * and the results are OR-folded, so the method neither early-returns on a
     * match nor leaks which step matched. Malformed input is rejected without
     * touching the secret.
     */
    public static function verify(
        string $secret,
        string $code,
        int $window = 1,
        ?int $timestamp = null,
        int $period = 30,
        int $digits = 6,
        string $algo = 'sha1',
    ): bool {
        if ($window < 0) {
            throw new InvalidArgumentException('Window must not be negative.');
        }
        if (preg_match('/^\d{' . $digits . '}$/', $code) !== 1) {
            return false;
        }

        $timestamp ??= time();
        $key = self::base32Decode($secret);
        $algo = self::normaliseAlgo($algo);
        $counter = intdiv($timestamp, $period);

        $matched = false;
        for ($offset = -$window; $offset <= $window; $offset++) {
            $candidate = self::hotp($key, $counter + $offset, $digits, $algo);
            // OR-fold without short-circuiting so timing is constant across the window.
            $matched = hash_equals($candidate, $code) || $matched;
        }

        return $matched;
    }

    /**
     * Build the otpauth:// provisioning URI for authenticator apps.
     *
     * The host renders this as a QR code with its own QR library; the framework
     * only emits the URI.
     */
    public static function otpauthUri(
        string $secret,
        string $accountLabel,
        string $issuer,
        int $period = 30,
        int $digits = 6,
        string $algo = 'sha1',
    ): string {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountLabel);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper(self::normaliseAlgo($algo)),
            'digits' => $digits,
            'period' => $period,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/' . $label . '?' . $query;
    }

    /**
     * Generate single-use recovery codes (high-entropy, `xxxx-xxxx` formatted).
     *
     * @return list<string>
     */
    public static function generateRecoveryCodes(int $count = 8, int $bytes = 10): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException('Count must be at least 1.');
        }

        if ($bytes < 2) {
            throw new InvalidArgumentException('Recovery codes need at least 2 characters per group.');
        }

        // Split the requested length across two `xxxx-xxxx` groups.
        $half = max(2, intdiv($bytes, 2));

        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = self::randomToken($half) . '-' . self::randomToken($half);
        }

        return $codes;
    }

    /**
     * Hash a recovery code for storage.
     *
     * Recovery codes are high-entropy random tokens, so a fast keyed-free
     * SHA-256 digest is sufficient (no need for a slow password hash); pair it
     * with {@see verifyRecoveryCode()} which compares in constant time.
     */
    public static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', $code);
    }

    /**
     * Find which stored hash a submitted recovery code matches.
     *
     * Returns the matching index (for the host to remove / mark consumed —
     * recovery codes are single-use) or false when none match. Every entry is
     * checked with {@see hash_equals()} so comparison is timing-safe.
     *
     * @param array<int,string> $hashedCodes
     */
    public static function verifyRecoveryCode(string $code, array $hashedCodes): int|false
    {
        $needle = self::hashRecoveryCode($code);
        $found = false;

        foreach ($hashedCodes as $index => $hash) {
            if (hash_equals($hash, $needle)) {
                $found = $index;
            }
        }

        return $found;
    }

    /**
     * Encode raw bytes as RFC 4648 base32 (uppercase, no padding).
     */
    public static function base32Encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $output .= self::BASE32_ALPHABET[(int) bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $output;
    }

    /**
     * Decode an RFC 4648 base32 string back to raw bytes.
     *
     * Tolerates lowercase and trailing `=` padding; rejects any character
     * outside the base32 alphabet.
     */
    public static function base32Decode(string $value): string
    {
        $value = rtrim(strtoupper($value), '=');
        if ($value === '') {
            return '';
        }

        $bits = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $position = strpos(self::BASE32_ALPHABET, $value[$i]);
            if ($position === false) {
                throw new InvalidArgumentException('Invalid base32 character encountered.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr((int) bindec($byte));
            }
        }

        return $output;
    }

    /**
     * HOTP (RFC 4226) over an explicit counter — the engine behind TOTP.
     */
    private static function hotp(string $key, int $counter, int $digits, string $algo): string
    {
        // 8-byte big-endian counter.
        $binCounter = pack('N*', 0, $counter);
        $hash = hash_hmac($algo, $binCounter, $key, true);

        // Dynamic truncation (RFC 4226 §5.3).
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        $code = $truncated % (10 ** $digits);

        return str_pad((string) $code, $digits, '0', STR_PAD_LEFT);
    }

    private static function normaliseAlgo(string $algo): string
    {
        $algo = strtolower($algo);
        if (!in_array($algo, ['sha1', 'sha256', 'sha512'], true)) {
            throw new InvalidArgumentException('Algorithm must be one of sha1, sha256, sha512.');
        }

        return $algo;
    }

    /**
     * A lowercase base32 token of the given character length.
     */
    private static function randomToken(int $chars): string
    {
        $alphabet = self::RECOVERY_ALPHABET;
        $max = strlen($alphabet) - 1;
        $token = '';
        for ($i = 0; $i < $chars; $i++) {
            $token .= $alphabet[random_int(0, $max)];
        }

        return $token;
    }
}
