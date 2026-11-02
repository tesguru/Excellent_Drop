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

// PendingDelete.net is the discovery source. Dropping today is the list that
// feeds the daily candidates, so it refreshes every ten minutes. The pending
// backlog and yesterday's drops change slowly and refresh less often.
foreach ((array) config('pendingdelete.schedule', []) as $list => $cron) {
    Schedule::command('pendingdelete:sync --list='.$list)
        ->cron((string) $cron)
        ->timezone('UTC')
        ->withoutOverlapping()
        ->onOneServer();
}

// The CZDS zone pipeline is kept for manual backfills only:
//   php artisan zones:run

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
