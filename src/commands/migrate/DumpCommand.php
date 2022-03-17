<?php

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\SchemaDumped;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HigherOrderTapProxy;
use Ions\Bundles\Path;
use Ions\Foundation\Kernel;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class DumpCommand extends Command
{
    protected $signature = 'schema:dump
                {--database= : The database connection to use}
                {--path= : The path where the schema dump file should be stored}
                {--prune : Delete all existing migration files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dump the given database schema';

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function handle(): void
    {
        $connections = Kernel::app()->get('db');
        $connection = $connections->connection($this->input->getOption('database'));

        $this->schemaState($connection)->dump(
            $connection, $path = $this->path($connection)
        );

        $dispatcher = new Dispatcher(Kernel::app());
        $dispatcher->dispatch(new SchemaDumped($connection, $path));

        $this->info('Database schema dumped successfully.');

        if ($this->option('prune')) {
            (new Filesystem)->deleteDirectory(
                Path::database('Schema'), false
            );

            $this->info('Migrations pruned successfully.');
        }
    }

    /**
     * Create a schema state instance for the given connection.
     *
     * @param Connection $connection
     * @return mixed
     */
    protected function schemaState(Connection $connection): mixed
    {
        // to use for migration table $table_name = Kernel::appConfig()->get('database.migrations', 'migrations');
        return $connection->getSchemaState()
            ->withMigrationTable('') // $connection->getTablePrefix() . $table_name to only output migration table
            ->handleOutputUsing(function ($type, $buffer) {
                $this->output->write($buffer);
            });
    }

    /**
     * Get the path that the dump should be written to.
     *
     * @param Connection $connection
     * @return HigherOrderTapProxy|mixed
     */
    protected function path(Connection $connection): mixed
    {
        $table_name = config('database.migrations', 'migrations');
        $migration_value = date('Y_m_d_His') . '_' . $connection->getName() . '_schema.dump';
        return tap($this->option('path') ?: Path::database('Migrations/' . $migration_value), function ($path) use ($migration_value, $table_name, $connection)  {
            (new Filesystem)->ensureDirectoryExists(dirname($path));

            $this->prepareDatabase($connection);

            $last_item = DB::connection($connection->getName())->table($table_name)->orderBy('batch', 'desc')->first();
            $batch = $last_item ? ($last_item->batch + 1) : 1;
            DB::connection($connection->getName())->table($table_name)->insert(['migration' => $migration_value, 'batch' => $batch]);
        });
    }

    protected function prepareDatabase(Connection $connection): void
    {
        $table_name = config('database.migrations', 'migrations');
        if (!Schema::connection($connection->getName())->hasTable($table_name)) {
            $args = '';
            if($this->option('database')){
                $args = '--database='.$this->option('database');
            }
            exec('php bin/ion migrate --install '.$args);
        }
    }
}
