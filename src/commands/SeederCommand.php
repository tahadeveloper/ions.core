<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ions\Bundles\Path;
use Ions\Support\File;

class SeederCommand extends Command
{
    protected $signature = 'make:seeder {name}';
    protected $description = 'Create seeder to full table with fake data.';

    public function handle(): void
    {
        $name = $this->argument('name'); // ExampleTextSeeder
        $name_cap = Str::remove('Seeder', $name); // ExampleText
        $name_snake = Str::snake($name_cap); // Example_Text
        $name_lower = Str::lower($name_snake); // example_text

        if (!File::exists(Path::database('Seeders'))) {
            File::makeDirectory(Path::database('Seeders'), 0755, true, true);
        }

        $new_file = Path::database('Seeders/'.$name.'.php');
        Storage::copy(Path::bin('commands/stubs/seeder.stub'), $new_file);

        $replace = Str::replace(
            ['{{ namespace }}', '{{ class }}', '{{ table }}'],
            ['App\\Database\\Seeders', $name, $name_lower],
            Storage::get($new_file));

        Storage::put($new_file, $replace);
        $this->info('Seeder created successfully, happy to see you.');
    }
}
