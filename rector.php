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
    ->withPhpSets(php83: true)
    // Laravel/Illuminate 12 upgrade set (Task 7.1: Illuminate 11 -> 12).
    ->withSets([
        LaravelSetList::LARAVEL_120,
    ])
    ->withRules([]);
