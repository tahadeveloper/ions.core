<?php

use Ions\Bundles\Path;
use Ions\Console\GeneratorCommand;

class MakeTestCommand extends GeneratorCommand
{
    protected $signature = 'make:test {name} {--unit : Generate a plain PHPUnit test that does not boot the kernel} {--force : Overwrite the file if it already exists}';
    protected $description = 'Create a new test in the host tests/ directory (Ions\Testing\TestCase by default).';

    protected function type(): string
    {
        return 'Test';
    }

    protected function stubPath(): string
    {
        $stub = $this->option('unit') ? 'test_unit.stub' : 'test.stub';

        return Path::bin('commands/stubs/' . $stub);
    }

    protected function targetPath(string $name): string
    {
        return Path::tests($name . '.php');
    }
}
