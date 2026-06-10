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

    public function __invoke(LogRecord $record): LogRecord
    {
        if ($record->context === []) {
            return $record;
        }

        return $record->with(context: $this->redact($record->context));
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::KEY_PATTERN, $key) === 1) {
                $data[$key] = self::MASK;
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
