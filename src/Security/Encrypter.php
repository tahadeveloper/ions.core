<?php

declare(strict_types=1);

namespace Ions\Security;

/**
 * Authenticated encryption over libsodium's XChaCha20-Poly1305 IETF AEAD.
 *
 * Payload format: "iev1:" + url-safe base64( 24-byte nonce || ciphertext+tag ).
 * The version prefix doubles as the AEAD "additional data", so a payload
 * re-labelled with a different version can never authenticate.
 *
 * Keys are 32 raw bytes. {@see fromAppKey()} derives one from APP_KEY via
 * HKDF-SHA256 with the info string "ions.encrypter.v1" — distinct from the
 * UrlSigner ("ions.urlsigner.v1") and JWT uses, so key material is never
 * shared across features even though they all stem from APP_KEY.
 *
 * For arrays/objects, JSON-encode before encrypt() and json_decode after
 * decrypt(): `$encrypter->encrypt(json_encode($data, JSON_THROW_ON_ERROR))`.
 */
final class Encrypter
{
    private const PREFIX = 'iev1:';
    private const HKDF_INFO = 'ions.encrypter.v1';
    private const KEY_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES; // 32
    private const NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES; // 24

    /**
     * @param string $key Exactly 32 raw bytes (use {@see fromAppKey()} to derive one).
     */
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $key,
    ) {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new \InvalidArgumentException(
                'Encrypter key must be exactly ' . self::KEY_BYTES . ' raw bytes; use Encrypter::fromAppKey() to derive one.'
            );
        }
    }

    /**
     * Derive an Encrypter from the application key (env APP_KEY).
     *
     * @throws \RuntimeException when the key is shorter than 32 bytes — the
     *                           same minimum the JWT subsystem enforces.
     */
    public static function fromAppKey(#[\SensitiveParameter] string $appKey): self
    {
        if (strlen($appKey) < 32) {
            throw new \RuntimeException(
                'APP_KEY must be at least 32 bytes to use the encrypter. Set a strong APP_KEY in your .env.'
            );
        }

        return new self(hash_hkdf('sha256', $appKey, self::KEY_BYTES, self::HKDF_INFO));
    }

    /**
     * Encrypt a plaintext into a URL-safe, versioned payload.
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, self::PREFIX, $nonce, $this->key);

        return self::PREFIX . rtrim(strtr(base64_encode($nonce . $ciphertext), '+/', '-_'), '=');
    }

    /**
     * Decrypt a payload produced by {@see encrypt()}.
     *
     * @throws DecryptException on tamper, wrong key, bad prefix, or malformed input.
     */
    public function decrypt(string $payload): string
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            throw new DecryptException('The payload could not be decrypted.');
        }

        $decoded = base64_decode(strtr(substr($payload, strlen(self::PREFIX)), '-_', '+/'), true);
        // Minimum size: nonce + Poly1305 tag (empty plaintext).
        if ($decoded === false || strlen($decoded) < self::NONCE_BYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new DecryptException('The payload could not be decrypted.');
        }

        $nonce = substr($decoded, 0, self::NONCE_BYTES);
        $ciphertext = substr($decoded, self::NONCE_BYTES);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, self::PREFIX, $nonce, $this->key);
        if ($plaintext === false) {
            throw new DecryptException('The payload could not be decrypted.');
        }

        return $plaintext;
    }
}
