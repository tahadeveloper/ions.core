<?php

declare(strict_types=1);

namespace Ions\Http;

use ArrayAccess;
use Countable;
use LogicException;
use Stringable;

/**
 * Immutable bag of validation error messages, keyed by field (Phase 10.3).
 *
 * Flashed to the session by {@see RedirectResponse::withErrors()} and rebuilt
 * on the next request by the errors() helper / Twig function. Templates always
 * receive a bag — empty when nothing was flashed — so has()/first()/any()
 * never need an existence check.
 *
 * Construction normalizes loose input: each field maps a string (or scalar /
 * Stringable) message or a list of them to a list<string>; non-stringable
 * values are dropped.
 *
 * @implements ArrayAccess<string, list<string>>
 */
final class ErrorBag implements ArrayAccess, Countable
{
    /** @var array<string, list<string>> */
    private array $messages = [];

    /**
     * @param array<array-key, mixed> $messages Field => message or list of messages.
     */
    public function __construct(array $messages = [])
    {
        foreach ($messages as $field => $value) {
            $list = [];
            foreach (is_array($value) ? $value : [$value] as $message) {
                if (is_scalar($message) || $message instanceof Stringable) {
                    $list[] = (string) $message;
                }
            }
            if ($list !== []) {
                $this->messages[(string) $field] = $list;
            }
        }
    }

    /**
     * Whether the given field has at least one message.
     */
    public function has(string $field): bool
    {
        return isset($this->messages[$field]);
    }

    /**
     * First message for a field, or the first message overall when $field is
     * null. Null when there is nothing to report.
     */
    public function first(?string $field = null): ?string
    {
        if ($field !== null) {
            return $this->messages[$field][0] ?? null;
        }

        foreach ($this->messages as $list) {
            return $list[0];
        }

        return null;
    }

    /**
     * All messages for one field (empty list when the field is clean).
     *
     * @return list<string>
     */
    public function get(string $field): array
    {
        return $this->messages[$field] ?? [];
    }

    /**
     * Every message across all fields, flattened.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return array_merge(...array_values($this->messages) ?: [[]]);
    }

    /**
     * Whether the bag holds any message at all.
     */
    public function any(): bool
    {
        return $this->messages !== [];
    }

    /**
     * Total number of messages (across all fields).
     */
    public function count(): int
    {
        return array_sum(array_map('count', $this->messages));
    }

    /**
     * The raw field => list<string> map (the shape flashed to the session).
     *
     * @return array<string, list<string>>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    /**
     * @return list<string>
     */
    public function offsetGet(mixed $offset): array
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('ErrorBag is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('ErrorBag is read-only.');
    }
}
