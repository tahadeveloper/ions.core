<?php

use Illuminate\Console\Command;
use Ions\Bundles\Path;
use Ions\Database\Seeder;

class SeedCommand extends Command
{
    protected $signature = 'db:seed
        {--class= : The seeder class to run (defaults to DatabaseSeeder)}
        {--database= : The database connection to use}';
    protected $description = 'Run a database seeder (database/seeders).';

    public function handle(): int
    {
        $class = (string) ($this->option('class') ?: 'DatabaseSeeder');

        $seeder = $this->resolveSeeder($class);

        if ($seeder === null) {
            $this->error("Seeder [{$class}] not found in " . Path::database('seeders'));

            return self::FAILURE;
        }

        if ($seeder instanceof Seeder) {
            $seeder->setCommand($this);
        }

        if (method_exists($seeder, 'seed')) {
            $method = 'seed';
        } elseif (method_exists($seeder, 'run')) {
            $method = 'run';
        } else {
            $this->error('Seeder [' . get_class($seeder) . '] has no seed() or run() method.');

            return self::FAILURE;
        }

        $this->info('Seeding: ' . get_class($seeder));
        $seeder->$method();
        $this->info('Database seeded successfully.');

        return self::SUCCESS;
    }

    /**
     * Resolve a seeder instance from a class name. Supports a fully-qualified
     * name, the host's Database\Seeders (new layout) / App\Database\Seeders
     * (legacy) namespace, and — when the host hasn't dumped its autoload —
     * requiring the file directly from database/seeders, mirroring
     * MigrateCommand::resolveSchema's file-loading robustness.
     */
    private function resolveSeeder(string $class): ?object
    {
        if (str_contains($class, '\\') && class_exists($class)) {
            return new $class();
        }

        $fqcn = (Path::usesDatabaseLayout() ? 'Database\\Seeders\\' : 'App\\Database\\Seeders\\') . $class;

        if (class_exists($fqcn)) {
            return new $fqcn();
        }

        $file = Path::database('seeders/' . $class . '.php');

        if (is_file($file)) {
            require_once $file;

            if (class_exists($fqcn, false)) {
                return new $fqcn();
            }

            if (class_exists($class, false)) {
                return new $class();
            }
        }

        return null;
    }
}
