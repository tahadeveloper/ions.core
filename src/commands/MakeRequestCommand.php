<?php

use Ions\Bundles\Path;
use Ions\Console\GeneratorCommand;

class MakeRequestCommand extends GeneratorCommand
{
    protected $signature = 'make:request {name} {--force : Overwrite the file if it already exists}';
    protected $description = 'Create a new form request class (Ions\Http\FormRequest).';

    protected function type(): string
    {
        return 'Request';
    }

    protected function stubPath(): string
    {
        return Path::bin('commands/stubs/request.stub');
    }

    protected function targetPath(string $name): string
    {
        return Path::src('Http/Requests/' . $name . '.php');
    }
}
