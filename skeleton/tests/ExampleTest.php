<?php

declare(strict_types=1);

namespace Tests;

use Ions\Testing\TestCase;

final class ExampleTest extends TestCase
{
    /** Host application root — the directory containing config/ and routes/. */
    protected string $basePath = __DIR__ . '/..';

    public function test_the_welcome_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Ions PHP framework');
    }
}
