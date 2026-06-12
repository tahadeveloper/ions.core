<?php

declare(strict_types=1);

return [
    // Map of event class => array of listener classes. Listeners are resolved
    // via the container; their handle() method receives the event object.
    'listen' => [
        // \Ions\Events\RequestHandled::class => [\App\Listeners\LogRequest::class],
    ],
];
