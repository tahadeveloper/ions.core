<?php

use Ions\Bundles\Path;
use Ions\Console\GeneratorCommand;

class MakeJobCommand extends GeneratorCommand
{
    protected $signature = 'make:job {name} {--force : Overwrite the file if it already exists}';
    protected $description = 'Create a new queue job class (Ions\Queue\Job).';

    protected function type(): string
    {
        return 'Job';
    }

    protected function stubPath(): string
    {
        return Path::bin('commands/stubs/job.stub');
    }

    protected function targetPath(string $name): string
    {
        return Path::src('Jobs/' . $name . '.php');
    }
}
