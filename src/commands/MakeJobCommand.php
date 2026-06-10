<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ions\Bundles\Path;
use Ions\Support\File;

class MakeJobCommand extends Command
{
    protected $signature = 'make:job {name} {--force : Overwrite the file if it already exists}';
    protected $description = 'Create a new queue job class (Ions\Queue\Job).';

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (!File::exists(Path::src('Jobs'))) {
            File::makeDirectory(Path::src('Jobs'), 0755, true, true);
        }

        $new_file = Path::src('Jobs/' . $name . '.php');

        if (File::exists($new_file)) {
            if (!$this->option('force')) {
                $this->error('Job already exists: ' . $name . ' (use --force to overwrite)');
                return self::FAILURE;
            }
            File::delete($new_file);
        }

        Storage::copy(Path::bin('commands/stubs/job.stub'), $new_file);

        $replace = Str::replace(
            ['{{ class }}'],
            [$name],
            Storage::get($new_file)
        );

        Storage::put($new_file, $replace);

        $this->info('Job created successfully: ' . $name);

        return self::SUCCESS;
    }
}
