<?php

declare(strict_types=1);

return [
    // 'native' = real PHP session. Host test suites usually set
    // SESSION_DRIVER=array so no native session is started under the CLI.
    'driver' => env('SESSION_DRIVER', 'native'),

    'name' => 'ion_session',

    // Cookie lifetime in seconds (0 = until the browser closes).
    'lifetime' => 0,

    // Cookie flags are secure BY DEFAULT since 4.1 (see UPGRADE-4.1.md):
    //   cookie_secure => true, cookie_httponly => true, cookie_samesite => 'lax'
    // Serving over plain HTTP in local dev? Set cookie_secure explicitly —
    // otherwise browsers will not send the session cookie:
    'cookie_secure' => 'auto',   // follows the request scheme; or false
];
