<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Ions\Bundles\Path;
use Ions\Foundation\Kernel;

class RollBackCommand extends Command
{
    protected $signature = 'migrate:rollback
        {--database= : The database connection to use}
        {--step= : Number of steps back default is 1}';
    protected $description = 'Rollback schema to previous version';

    public function handle(): void
    {
        $connections = Kernel::app()->get('db');
        $connection = $connections->connection($this->input->getOption('database') ?? 'default');
        $table_name = config('database.migrations', 'migrations');

        $step = $this->input->getOption('step') ?? 0;

        $row_last_item = DB::connection($connection->getName())->table($table_name)->orderBy('batch', 'desc');
        $last_item = $row_last_item->first('batch');
        if($last_item){
            $last_batch = $last_item->batch;
            $target_batch = $last_batch - $step;
            $target_rollback =  DB::connection($connection->getName())->table($table_name)->where('batch',$target_batch)->orderBy('batch', 'desc')->first();
            if($target_rollback && Storage::exists(Path::database('migrations/'.$target_rollback->migration))){

                Schema::connection($connection->getName())->dropAllTables();
                Schema::connection($connection->getName())->dropAllViews();

                DB::connection($connection->getName())->unprepared(file_get_contents(Path::database('migrations/'.$target_rollback->migration)));
                $this->info('Database Rollback schema install successfully.');

                exit();
            }
            $this->info('There are no migration file with steps for rollback');
            ray($target_rollback);

            exit();
        }
        $this->info('There are no migration for rollback');

    }
}
