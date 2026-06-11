<?php

declare(strict_types=1);

namespace Ions\Bundles;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor stamping every record with a per-request correlation id.
 *
 * The id (8 random bytes, hex-encoded — 16 chars) is generated lazily on the
 * first log write and memoized for the rest of the request, so every channel
 * (and {@see Logs::create()} loggers) shares the same `extra.request_id`
 * within one request. Worker runtimes get a fresh id per request because
 * {@see \Ions\Foundation\Kernel::resetForRequest()} calls {@see self::reset()}.
 */
final class RequestIdProcessor implements ProcessorInterface
{
    private static ?string $id = null;

    /**
     * The current request's correlation id (lazily generated, memoized).
     */
    public static function id(): string
    {
        return self::$id ??= bin2hex(random_bytes(8));
    }

    /**
     * Forget the memoized id so the next log write mints a fresh one.
     * Called from Kernel::resetForRequest() between worker-mode requests.
     */
    public static function reset(): void
    {
        self::$id = null;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: $record->extra + ['request_id' => self::id()]);
    }
}
