<?php

use Illuminate\Console\Command;
use Ions\Console\Schedule;

class ScheduleRunCommand extends Command
{
    protected $signature = 'schedule:run';
    protected $description = 'Run the scheduled tasks that are due.';

    public function handle(): int
    {
        $scheduler = Schedule::build();
        $scheduler->run();

        $output = $scheduler->getVerboseOutput();
        if ($output !== '') {
            $this->line($output);
        } else {
            $this->info('No scheduled tasks are due to run.');
        }

        return self::SUCCESS;
    }
}
