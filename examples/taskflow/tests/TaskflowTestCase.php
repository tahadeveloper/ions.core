<?php

declare(strict_types=1);

namespace Tests;

use Ions\Testing\TestCase;

/**
 * Base test case for the Taskflow example suite. Points the framework
 * TestCase at the example application root (the directory containing
 * config/ and routes/) so every test boots a real kernel in-process.
 */
abstract class TaskflowTestCase extends TestCase
{
    protected function basePath(): string
    {
        return dirname(__DIR__);
    }
}
