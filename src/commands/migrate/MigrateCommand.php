<?php

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ions\Bundles\Path;
use Ions\Foundation\Kernel;
use Ions\Support\DB;

class MigrateCommand extends Command
{
    protected $signature = 'migrate
        {--database= : The database connection to use}
        {--install : create table for migrations}
        {--refresh : remove all table by down}';
    protected $description = 'Run schema classes up or down and install table';

    public function handle(): void
    {
        $connections = Kernel::app()->get('db');
        $connection = $connections->connection($this->input->getOption('database') ?? 'default');
        $table_name = config('database.migrations', 'migrations');

        if ($this->option('install')) {
            $this->info($this->ensureMigrationsTable($connection->getName(), $table_name)
                ? 'Migrations table created successfully.'
                : 'Migrations table exists.');

        } elseif ($this->option('refresh')) {
            foreach (glob(Path::database('migrations').'/*.php') as $file) {
                $class = basename($file, '.php');
                $obj = $this->resolveSchema($file, $class);
                Schema::disableForeignKeyConstraints();
                $obj->down();

                $this->info('Schema class '.$class.' Removed successfully.');
            }

            $this->info('Schema removed tables successfully.');
        } else {
            $db_name  = $this->input->getOption('database') ?? 'default';

            // Auto-create the bookkeeping table on first run — `migrate` should
            // just work without a separate `migrate --install` step (Laravel
            // parity). Without this, the very first `migrate` on a fresh database
            // throws "Table 'migrations' doesn't exist" on the lookup below.
            if ($this->ensureMigrationsTable($connection->getName(), $table_name)) {
                $this->info('Migrations table created.');
            }

            foreach (glob(Path::database('migrations').'/*.php') as $file) {
                $class = basename($file, '.php');
                //check if migration is already installed
                $migration_installed = DB::table($table_name)->where('migration', $class)->first();
                if (!$migration_installed) {
                    $obj = $this->resolveSchema($file, $class);
                    Schema::connection($db_name)->enableForeignKeyConstraints();
                    $obj->up();
                    DB::table($table_name)->insert(['migration' => $class, 'batch' => 1]);
                    $this->info('Schema class '.$class.' installed successfully.');
                }
            }

            $this->info('Migrated successfully.');
        }
    }

    /**
     * Ensure the migrations bookkeeping table exists on the given connection,
     * creating it if missing. Returns true when it was created, false when it
     * already existed. Idempotent — safe to call on every `migrate`.
     */
    private function ensureMigrationsTable(string $connection, string $table): bool
    {
        if (Schema::connection($connection)->hasTable($table)) {
            return false;
        }

        Schema::connection($connection)->create($table, static function (Blueprint $table) {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
        });

        return true;
    }

    /**
     * Load a globbed schema file and return its migration object. Supports
     * both the classic named-class style (App\Database\Schema\{Class}) and
     * stub-style files that `return new class extends Migration {}` (the
     * shipped jobs/notifications stubs). Loading the file directly is what
     * lets the host-root database/migrations layout (4.6) run without a
     * dedicated autoload mapping.
     *
     * The included value is cached per realpath: `require_once` returns the
     * file's return value ONLY on first inclusion — a SECOND `require_once`
     * of the same path in one process returns int(1), which would drop an
     * anonymous-class stub (`return new class extends Migration {}`) and
     * fall through to `new App\Database\Schema\{basename}` (Class not found).
     * The cache keeps the object alive so the same file resolves identically
     * however many times it is re-resolved in one process (refresh+migrate,
     * queue workers, host scripts running migrate twice). `require_once` runs
     * at most once per file here, so a named-class file never redeclares.
     */
    private function resolveSchema(string $file, string $class): object
    {
        static $cache = [];

        $key = realpath($file) ?: $file;

        if (!array_key_exists($key, $cache)) {
            $cache[$key] = require_once $file;
        }

        $loaded = $cache[$key];

        if (is_object($loaded)) {
            return $loaded;
        }

        $load_class = 'App\\Database\\Schema\\'.$class;

        return new $load_class();
    }
}
