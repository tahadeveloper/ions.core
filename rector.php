<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withSkip([
        __DIR__ . '/src/commands/stubs',
    ])
    // Conservative starting point: target the current language level only.
    // Upgrade rule sets (Laravel/Symfony) are enabled in later modernization phases.
    ->withPhpSets(php82: true)
    ->withRules([]);
