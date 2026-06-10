<?php

return [
    // In-memory driver: the test/CLI environment cannot share the real PHP
    // session (other suites may have started one via Sentinel).
    'driver' => 'array',
];
