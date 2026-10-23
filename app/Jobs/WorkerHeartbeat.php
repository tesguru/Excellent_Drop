<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Heartbeat for the zones worker.
 *
 * The scheduler dispatches this job every minute. When the worker stops the
 * heartbeat stops and the dashboard turns the status light red.
 */
class WorkerHeartbeat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct()
    {
        // The heartbeat must not sit behind a multi-hour pipeline run, so it
        // has its own queue served by a dedicated worker.
        $this->onQueue((string) config('zones.heartbeat_queue', 'heartbeat'));
    }

    public function handle(): void
    {
        Cache::put(
            (string) config('zones.heartbeat_cache_key'),
            now()->toIso8601String(),
            now()->addMinutes(10),
        );
    }
}
