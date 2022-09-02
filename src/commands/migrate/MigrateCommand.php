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

        if($this->option('install')){
            Schema::connection($connection->getName());
            if (!Schema::connection($connection->getName())->hasTable($table_name)) {
                Schema::connection($connection->getName())->create($table_name, static function (Blueprint $table) {
                    $table->increments('id');
                    $table->string('migration');
                    $table->integer('batch');
                });

                $this->info('Migrations table created successfully.');
                exit();
            }

            $this->info('Migrations table exits.');

        }elseif($this->option('refresh')){
            foreach (glob(Path::database('Schema').'/*.php') as $file)
            {
                $class = basename($file, '.php');
                $load_class = 'App\\Database\Schema\\'.$class;
                $obj = new $load_class;
                Schema::disableForeignKeyConstraints();
                $obj->down();

                $this->info('Schema class '.$class.' Removed successfully.');
            }

            $this->info('Schema removed tables successfully.');
        }else{
            $db_name  = $this->input->getOption('database') ?? 'default';
            foreach (glob(Path::database('Schema').'/*.php') as $file)
            {
                $class = basename($file, '.php');
                //check if migration is already installed
                $migration_installed = DB::table($table_name)->where('migration', $class)->first();
                if(!$migration_installed){
                    $load_class = 'App\Database\Schema\\'.$class;
                    $obj = new $load_class;
                    Schema::connection($db_name)->enableForeignKeyConstraints();
                    $obj->up();
                    DB::table($table_name)->insert(['migration' => $class, 'batch' => 1]);
                    $this->info('Schema class '.$class.' installed successfully.');
                }
            }

            $this->info('Migrated successfully.');
        }
    }
}
