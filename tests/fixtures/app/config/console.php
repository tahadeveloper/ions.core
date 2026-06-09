<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Host Console Commands
    |--------------------------------------------------------------------------
    |
    | Explicit list of fully-qualified command class names that the console
    | Kernel should register in addition to the framework commands and any
    | classes auto-discovered from the host "app/Commands" directory.
    |
    */
    'commands' => [
        \IonsFixture\Commands\HelloCommand::class,
    ],
];
