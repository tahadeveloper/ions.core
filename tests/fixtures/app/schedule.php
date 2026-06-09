<?php

/*
|--------------------------------------------------------------------------
| Scheduled Tasks (fixture)
|--------------------------------------------------------------------------
|
| Returns a closure receiving the GO\Scheduler. Used by the console
| ScheduleTest to verify the schedule:run command and Schedule::build().
|
*/

return function (\GO\Scheduler $schedule): void {
    $schedule->call(static function (): string {
        return 'fixture scheduled task ran';
    })->everyMinute();
};
