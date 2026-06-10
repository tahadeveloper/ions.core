<?php

declare(strict_types=1);

namespace Ions\Security;

/**
 * Thrown by {@see Encrypter::decrypt()} when a payload cannot be decrypted:
 * tampered ciphertext, wrong key, malformed base64, or an unknown version
 * prefix. The message is intentionally generic — never leak which check failed
 * to callers that might echo it to users.
 */
class DecryptException extends \RuntimeException
{
}
