<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withSkip([
        __DIR__ . '/src/commands/stubs',
    ])
    // Target the current language level.
    ->withPhpSets(php82: true)
    // Laravel/Illuminate 10 upgrade set (Task 4.2: Illuminate 9 -> 10).
    ->withSets([
        LaravelSetList::LARAVEL_100,
    ])
    ->withRules([]);
