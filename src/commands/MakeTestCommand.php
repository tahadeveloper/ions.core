<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ions\Bundles\Path;
use Ions\Support\File;

class MakeTestCommand extends Command
{
    protected $signature = 'make:test {name} {--unit : Generate a plain PHPUnit test that does not boot the kernel} {--force : Overwrite the file if it already exists}';
    protected $description = 'Create a new test in the host tests/ directory (Ions\Testing\TestCase by default).';

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (!File::exists(Path::tests())) {
            File::makeDirectory(Path::tests(), 0755, true, true);
        }

        $new_file = Path::tests($name . '.php');

        if (File::exists($new_file)) {
            if (!$this->option('force')) {
                $this->error('Test already exists: ' . $name . ' (use --force to overwrite)');
                return self::FAILURE;
            }
            File::delete($new_file);
        }

        $stub = $this->option('unit') ? 'test_unit.stub' : 'test.stub';

        Storage::copy(Path::bin('commands/stubs/' . $stub), $new_file);

        $replace = Str::replace(
            ['{{ class }}'],
            [$name],
            Storage::get($new_file)
        );

        Storage::put($new_file, $replace);

        $this->info('Test created successfully: ' . $name);

        return self::SUCCESS;
    }
}
