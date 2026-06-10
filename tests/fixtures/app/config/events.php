<?php

return [
    // Map of event class => array of listener classes (resolved via the
    // container; their handle() method receives the event object).
    'listen' => [
        \Ions\Events\RequestHandled::class => [
            \IonsFixture\RecordingListener::class,
        ],
    ],
];
