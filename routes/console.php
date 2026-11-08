<?php

use App\Jobs\WorkerHeartbeat;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| DropWatch schedule
|--------------------------------------------------------------------------
|
| One cron entry runs the scheduler every minute:
|
|   * * * * * php artisan schedule:run
|
*/

// The daily zone pipeline. ICANN publishes after 06:00 UTC, so the run starts
// at 08:00 UTC (09:00 Africa/Lagos) to give the files time to appear. The
// schedule timezone is explicit: ZONES_SCHEDULE is a cron expression in UTC.
Schedule::command('zones:run')
    ->cron((string) config('zones.schedule', '0 8 * * *'))
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground(false);

// Worker heartbeat. The light in the dashboard turns red when this stops.
Schedule::job(new WorkerHeartbeat)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Rows that are stuck in the running state are marked as failed.
Schedule::command('zones:clear-stuck')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
