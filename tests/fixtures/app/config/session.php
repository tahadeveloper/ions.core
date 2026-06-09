<?php

return [
    // Storage driver: 'native' (real PHP session) or 'array'/'mock' (in-memory).
    // The test/CLI environment uses 'array' so no real session is required.
    'driver' => 'array',

    // Session cookie name (native driver).
    'name' => 'ion_session',

    // Cookie lifetime in seconds (0 = until the browser closes).
    'lifetime' => 0,

    // Cookie flags (native driver).
    'cookie_secure' => false,
    'cookie_httponly' => true,
    'cookie_samesite' => 'lax',
];
