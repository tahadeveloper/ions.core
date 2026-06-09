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
    // Laravel/Illuminate 11 upgrade set (Task 4.6.3: Illuminate 10 -> 11).
    ->withSets([
        LaravelSetList::LARAVEL_110,
    ])
    ->withRules([]);
