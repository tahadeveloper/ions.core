<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ions\Bundles\Path;
use Ions\Support\File;

class MakeServiceProviderCommand extends Command
{
    protected $signature = 'make:service-provider {name}';
    protected $description = 'Create a new container ServiceProvider class.';

    public function handle(): void
    {
        $name = $this->argument('name');

        if (!File::exists(Path::src('Providers'))) {
            File::makeDirectory(Path::src('Providers'), 0755, true, true);
        }

        $new_file = Path::src('Providers/' . $name . '.php');

        if (File::exists($new_file)) {
            $this->error('ServiceProvider already exists: ' . $name);
            return;
        }

        Storage::copy(Path::bin('commands/stubs/service_provider.stub'), $new_file);

        $replace = Str::replace(
            ['{{ class }}'],
            [$name],
            Storage::get($new_file)
        );

        Storage::put($new_file, $replace);

        $this->info('ServiceProvider created successfully: ' . $name);
    }
}
