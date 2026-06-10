<?php

declare(strict_types=1);

namespace Ions\Media;

use RuntimeException;

/**
 * Raised by {@see Image} for any image processing failure — a missing or
 * unreadable file, an undecodable / unsupported format, or an encode error.
 *
 * It wraps the underlying Intervention Image exception (as the previous
 * throwable) so the original cause stays available without leaking
 * Intervention's own exception types to callers.
 */
class ImageException extends RuntimeException
{
}
