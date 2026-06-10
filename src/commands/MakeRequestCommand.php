<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ions\Bundles\Path;
use Ions\Support\File;

class MakeRequestCommand extends Command
{
    protected $signature = 'make:request {name} {--force : Overwrite the file if it already exists}';
    protected $description = 'Create a new form request class (Ions\Http\FormRequest).';

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (!File::exists(Path::src('Http/Requests'))) {
            File::makeDirectory(Path::src('Http/Requests'), 0755, true, true);
        }

        $new_file = Path::src('Http/Requests/' . $name . '.php');

        if (File::exists($new_file)) {
            if (!$this->option('force')) {
                $this->error('Request already exists: ' . $name . ' (use --force to overwrite)');
                return self::FAILURE;
            }
            File::delete($new_file);
        }

        Storage::copy(Path::bin('commands/stubs/request.stub'), $new_file);

        $replace = Str::replace(
            ['{{ class }}'],
            [$name],
            Storage::get($new_file)
        );

        Storage::put($new_file, $replace);

        $this->info('Request created successfully: ' . $name);

        return self::SUCCESS;
    }
}
