<?php

namespace Ions\Foundation;

use ArrayAccess;
use Ions\Support\Arr;
use ReturnTypeWillChange;

class Config implements ArrayAccess
{
    /**
     * All the configuration items.
     *
     * @var array
     */
    protected array $items = [];

    /**
     * Create a new configuration repository.
     *
     * @param array $items
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
     * @param array|string $key
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
     * @param array $keys
     * @return array
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
     * Set a given configuration value.
     *
     * @param array|string $key
     * @param mixed $value
     * @return void
     */
    public function set(array|string $key, mixed $value = null): void
    {
        $keys = is_array($key) ? $key : [$key => $value];

        foreach ($keys as  $key => $value) {
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
     * @return array
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
    public function offsetUnset($offset):void
    {
        $this->set($offset, null);
    }
}