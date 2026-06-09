<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ions\Bundles\Path;
use Ions\Support\File;

class MakeMiddlewareCommand extends Command
{
    protected $signature = 'make:middleware {name}';
    protected $description = 'Create a new HTTP middleware class.';

    public function handle(): void
    {
        $name = $this->argument('name');

        if (!File::exists(Path::src('Http/Middleware'))) {
            File::makeDirectory(Path::src('Http/Middleware'), 0755, true, true);
        }

        $new_file = Path::src('Http/Middleware/' . $name . '.php');

        if (File::exists($new_file)) {
            $this->error('Middleware already exists: ' . $name);
            return;
        }

        Storage::copy(Path::bin('commands/stubs/middleware.stub'), $new_file);

        $replace = Str::replace(
            ['{{ class }}'],
            [$name],
            Storage::get($new_file)
        );

        Storage::put($new_file, $replace);

        $this->info('Middleware created successfully: ' . $name);
    }
}
