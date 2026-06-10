<?php

declare(strict_types=1);

namespace Ions\Foundation;

use ArrayAccess;
use InvalidArgumentException;
use Ions\Support\Arr;
use ReturnTypeWillChange;

/**
 * @implements ArrayAccess<string, mixed>
 */
class Config implements ArrayAccess
{
    /**
     * All the configuration items.
     *
     * @var array<array-key, mixed>
     */
    protected array $items = [];

    /**
     * Create a new configuration repository.
     *
     * @param array<array-key, mixed> $items
     * @return void
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Determine if the given configuration value exists.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return Arr::has($this->items, $key);
    }

    /**
     * Get the specified configuration value.
     *
     * @param array<array-key, mixed>|string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function get(array|string $key, mixed $default = null): mixed
    {
        if (is_array($key)) {
            return $this->getMany($key);
        }


        return Arr::get($this->items, $key, $default);
    }

    /**
     * Get many configuration values.
     *
     * @param array<array-key, mixed> $keys
     * @return array<string, mixed>
     */
    public function getMany(array $keys): array
    {
        $config = [];

        foreach ($keys as $key => $default) {
            if (is_numeric($key)) {
                [$key, $default] = [$default, null];
            }

            $config[$key] = Arr::get($this->items, $key, $default);
        }

        return $config;
    }

    /**
     * Get the specified configuration value as a string, or throw.
     *
     * Assertion-style accessor (mirrors Laravel's typed Config getters):
     * no coercion — a non-string value (including null for a missing key
     * without a default) throws InvalidArgumentException.
     *
     * @param string $key
     * @param string|null $default
     * @return string
     *
     * @throws InvalidArgumentException
     */
    public function string(string $key, ?string $default = null): string
    {
        $value = $this->get($key, $default);

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('Configuration value for key [%s] must be a string, %s given.', $key, get_debug_type($value))
            );
        }

        return $value;
    }

    /**
     * Get the specified configuration value as an integer, or throw.
     *
     * No coercion — the string '1' is not an int.
     *
     * @param string $key
     * @param int|null $default
     * @return int
     *
     * @throws InvalidArgumentException
     */
    public function integer(string $key, ?int $default = null): int
    {
        $value = $this->get($key, $default);

        if (! is_int($value)) {
            throw new InvalidArgumentException(
                sprintf('Configuration value for key [%s] must be an integer, %s given.', $key, get_debug_type($value))
            );
        }

        return $value;
    }

    /**
     * Alias of integer().
     *
     * @param string $key
     * @param int|null $default
     * @return int
     *
     * @throws InvalidArgumentException
     */
    public function int(string $key, ?int $default = null): int
    {
        return $this->integer($key, $default);
    }

    /**
     * Get the specified configuration value as a boolean, or throw.
     *
     * No coercion — the ints 0/1 are not bools.
     *
     * @param string $key
     * @param bool|null $default
     * @return bool
     *
     * @throws InvalidArgumentException
     */
    public function boolean(string $key, ?bool $default = null): bool
    {
        $value = $this->get($key, $default);

        if (! is_bool($value)) {
            throw new InvalidArgumentException(
                sprintf('Configuration value for key [%s] must be a boolean, %s given.', $key, get_debug_type($value))
            );
        }

        return $value;
    }

    /**
     * Alias of boolean().
     *
     * @param string $key
     * @param bool|null $default
     * @return bool
     *
     * @throws InvalidArgumentException
     */
    public function bool(string $key, ?bool $default = null): bool
    {
        return $this->boolean($key, $default);
    }

    /**
     * Get the specified configuration value as an array, or throw.
     *
     * @param string $key
     * @param array<array-key, mixed>|null $default
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function array(string $key, ?array $default = null): array
    {
        $value = $this->get($key, $default);

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                sprintf('Configuration value for key [%s] must be an array, %s given.', $key, get_debug_type($value))
            );
        }

        return $value;
    }

    /**
     * Get the specified configuration value as a float, or throw.
     *
     * No coercion — an int is not a float.
     *
     * @param string $key
     * @param float|null $default
     * @return float
     *
     * @throws InvalidArgumentException
     */
    public function float(string $key, ?float $default = null): float
    {
        $value = $this->get($key, $default);

        if (! is_float($value)) {
            throw new InvalidArgumentException(
                sprintf('Configuration value for key [%s] must be a float, %s given.', $key, get_debug_type($value))
            );
        }

        return $value;
    }

    /**
     * Set a given configuration value.
     *
     * @param array<string, mixed>|string $key
     * @param mixed $value
     * @return void
     */
    public function set(array|string $key, mixed $value = null): void
    {
        $keys = is_array($key) ? $key : [$key => $value];

        foreach ($keys as $key => $value) {
            Arr::set($this->items, $key, $value);
        }
    }

    /**
     * Prepend a value onto an array configuration value.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function prepend(string $key, mixed $value): void
    {
        $array = $this->get($key);

        array_unshift($array, $value);

        $this->set($key, $array);
    }

    /**
     * Push a value onto an array configuration value.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function push(string $key, mixed $value): void
    {
        $array = $this->get($key);

        $array[] = $value;

        $this->set($key, $array);
    }

    /**
     * Get all the configuration items for the application.
     *
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Determine if the given configuration option exists.
     *
     * @param string $offset
     * @return bool
     */
    #[ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return $this->has($offset);
    }

    /**
     * Get a configuration option.
     *
     * @param string $offset
     * @return mixed
     */
    #[ReturnTypeWillChange]
    public function offsetGet($offset): mixed
    {
        return $this->get($offset);
    }

    /**
     * Set a configuration option.
     *
     * @param string $offset
     * @param mixed $value
     * @return void
     */
    #[ReturnTypeWillChange]
    public function offsetSet($offset, $value): void
    {
        $this->set($offset, $value);
    }

    /**
     * Unset a configuration option.
     *
     * @param string $offset
     * @return void
     */
    #[ReturnTypeWillChange]
    public function offsetUnset($offset): void
    {
        $this->set($offset, null);
    }
}
