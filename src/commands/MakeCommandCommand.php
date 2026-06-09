<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ions\Bundles\Path;
use Ions\Support\File;

class MakeCommandCommand extends Command
{
    protected $signature = 'make:command {name} {--command= : The terminal signature the command listens for}';
    protected $description = 'Create a new Artisan-style console command class.';

    public function handle(): void
    {
        $name = (string) $this->argument('name');

        if (!File::exists(Path::src('Commands'))) {
            File::makeDirectory(Path::src('Commands'), 0755, true, true);
        }

        $new_file = Path::src('Commands/' . $name . '.php');

        if (File::exists($new_file)) {
            $this->error('Command already exists: ' . $name);
            return;
        }

        $signature = $this->option('command');
        if (!is_string($signature) || $signature === '') {
            $signature = 'app:' . Str::kebab(Str::replaceLast('Command', '', $name));
        }

        Storage::copy(Path::bin('commands/stubs/command.stub'), $new_file);

        $replace = Str::replace(
            ['{{ class }}', '{{ signature }}'],
            [$name, $signature],
            Storage::get($new_file)
        );

        Storage::put($new_file, $replace);

        $this->info('Command created successfully: ' . $name);
    }
}
