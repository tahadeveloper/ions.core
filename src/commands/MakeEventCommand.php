<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ions\Bundles\Path;
use Ions\Support\File;

class MakeEventCommand extends Command
{
    protected $signature = 'make:event {name} {--force : Overwrite the file if it already exists}';
    protected $description = 'Create a new event class (plain immutable payload object).';

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (!File::exists(Path::src('Events'))) {
            File::makeDirectory(Path::src('Events'), 0755, true, true);
        }

        $new_file = Path::src('Events/' . $name . '.php');

        if (File::exists($new_file)) {
            if (!$this->option('force')) {
                $this->error('Event already exists: ' . $name . ' (use --force to overwrite)');
                return self::FAILURE;
            }
            File::delete($new_file);
        }

        Storage::copy(Path::bin('commands/stubs/event.stub'), $new_file);

        $replace = Str::replace(
            ['{{ class }}'],
            [$name],
            Storage::get($new_file)
        );

        Storage::put($new_file, $replace);

        $this->info('Event created successfully: ' . $name);

        return self::SUCCESS;
    }
}
