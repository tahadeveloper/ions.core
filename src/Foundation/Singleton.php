<?php

declare(strict_types=1);

namespace Ions\Foundation;

use InvalidArgumentException;

/**
 * @phpstan-consistent-constructor
 */
abstract class Singleton
{
    private static array $instances = [];

    protected function __construct()
    {
    }

    protected function __clone()
    {
    }

    public function __wakeup(): void
    {
        throw new InvalidArgumentException("Cannot Un serialize a singleton.");
    }

    public static function getInstance(): Singleton
    {
        $cls = static::class;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new static();
        }
        return self::$instances[$cls];
    }

}
