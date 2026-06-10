<?php

use Ions\Bundles\Path;
use Ions\Console\GeneratorCommand;

class MakeEventCommand extends GeneratorCommand
{
    protected $signature = 'make:event {name} {--force : Overwrite the file if it already exists}';
    protected $description = 'Create a new event class (plain immutable payload object).';

    protected function type(): string
    {
        return 'Event';
    }

    protected function stubPath(): string
    {
        return Path::bin('commands/stubs/event.stub');
    }

    protected function targetPath(string $name): string
    {
        return Path::src('Events/' . $name . '.php');
    }
}
