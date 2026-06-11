<?php

use Illuminate\Support\Str;
use Ions\Bundles\Path;
use Ions\Console\GeneratorCommand;

class SeederCommand extends GeneratorCommand
{
    protected $signature = 'make:seeder {name} {--force : Overwrite the file if it already exists}';
    protected $description = 'Create a seeder: database/seeders (Database\\Seeders) on the host-root database/ layout, else {app|src}/Database/Seeders (App\\Database\\Seeders).';

    protected function type(): string
    {
        return 'Seeder';
    }

    protected function stubPath(): string
    {
        return Path::bin('commands/stubs/seeder.stub');
    }

    protected function targetPath(string $name): string
    {
        if (Path::usesDatabaseLayout()) {
            return Path::database('seeders/' . $name . '.php');
        }

        return Path::database('Seeders/' . $name . '.php');
    }

    protected function replacements(string $name): array
    {
        // ExampleTextSeeder -> example_text (the seeded table).
        $table = Str::lower(Str::snake(Str::remove('Seeder', $name)));

        return [
            '{{ namespace }}' => Path::usesDatabaseLayout() ? 'Database\\Seeders' : 'App\\Database\\Seeders',
            '{{ class }}' => $name,
            '{{ table }}' => $table,
        ];
    }

    public function handle(): int
    {
        $result = $this->generate();

        if ($result === self::SUCCESS && Path::usesDatabaseLayout()) {
            $this->noteDatabaseAutoload($this->argument('name'));
        }

        return $result;
    }

    /**
     * On the host-root database/ layout the seeder lands in the top-level
     * `Database\Seeders` namespace, which only autoloads when the host maps it.
     * Hint the composer.json entry when not yet autoloadable — info, not error.
     *
     * @param mixed $name
     */
    private function noteDatabaseAutoload($name): void
    {
        $name = is_string($name) ? $name : '';

        if ($name !== '' && class_exists('Database\\Seeders\\' . $name, false)) {
            return;
        }

        $this->info(
            'Note: this seeder uses the Database\\Seeders namespace. Ensure your host ' .
            'composer.json maps it for autoloading: "Database\\\\Seeders\\\\": "database/seeders/" ' .
            '(then run composer dump-autoload).'
        );
    }
}
