<?php

declare(strict_types=1);

namespace IonsFixture\Lifecycle;

/**
 * Static event recorder shared by the 9.3 lifecycle fixtures (controllers +
 * middleware) so tests can pin the EXACT hook firing order across a request.
 */
final class Recorder
{
    /** @var list<string> */
    public static array $events = [];

    public static function add(string $event): void
    {
        self::$events[] = $event;
    }

    public static function reset(): void
    {
        self::$events = [];
    }
}
