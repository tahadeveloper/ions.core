<?php

namespace IonsFixture\Commands;

use Illuminate\Console\Command;

class HelloCommand extends Command
{
    protected $signature = 'fixture:hello';
    protected $description = 'A fixture host command used by the console kernel tests.';

    public function handle(): int
    {
        $this->info('Hello from fixture');

        return self::SUCCESS;
    }
}
