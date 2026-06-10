<?php

declare(strict_types=1);

namespace Ions\Bundles;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor masking secret-bearing values in log context arrays.
 *
 * Any context key matching password / passwd / token / secret / authorization /
 * api_key / api-key (case-insensitive, substring) has its value replaced with
 * "[REDACTED]" — recursively, so nested arrays are covered. Non-matching keys
 * are left untouched. Registered by default in {@see Logs::create()}.
 */
final class RedactionProcessor implements ProcessorInterface
{
    private const KEY_PATTERN = '/password|passwd|token|secret|authorization|api[_-]?key/i';

    public const MASK = '[REDACTED]';

    /**
     * Whether a key looks secret-bearing (password/token/secret/authorization/
     * api key, case-insensitive substring). Shared with {@see \Ions\Http\DebugPage}
     * so the debug error page and log redaction stay in sync.
     */
    public static function isSensitiveKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if ($record->context === []) {
            return $record;
        }

        return $record->with(context: self::redact($record->context));
    }

    /**
     * Recursively replace sensitive values with {@see MASK}. The optional
     * $isSensitive predicate widens the default key pattern (e.g.
     * {@see \Ions\Http\DebugPage} adds Cookie / php-auth headers); it defaults
     * to {@see isSensitiveKey()}, which is what log redaction uses.
     *
     * @param array<array-key, mixed> $data
     * @param null|callable(string): bool $isSensitive
     * @return array<array-key, mixed>
     */
    public static function redact(array $data, ?callable $isSensitive = null): array
    {
        $isSensitive ??= self::isSensitiveKey(...);

        foreach ($data as $key => $value) {
            if (is_string($key) && $isSensitive($key)) {
                $data[$key] = self::MASK;
                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::redact($value, $isSensitive);
            }
        }

        return $data;
    }
}
