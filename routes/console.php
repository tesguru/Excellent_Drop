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

// The corpus pins each TLD to its newest zone snapshot, so it is rewritten
// once a day and after any manual zone load:
//   php artisan zones:refresh-corpus
Schedule::command('zones:refresh-corpus')
    ->dailyAt('00:20')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();

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
