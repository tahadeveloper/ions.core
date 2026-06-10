<?php

declare(strict_types=1);

namespace IonsFixture;

use Ions\Events\RequestHandled;

/**
 * Test listener that records the RequestHandled events it observes.
 * Registered via the fixture config/events.php listen map.
 */
final class RecordingListener
{
    /** @var list<RequestHandled> */
    public static array $handled = [];

    public function handle(RequestHandled $event): void
    {
        self::$handled[] = $event;
    }
}
