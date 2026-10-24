<?php

namespace App\Console\Commands;

use App\Jobs\DownloadZones;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RunPipeline extends Command
{
    protected $signature = 'zones:run {--queue= : Queue to dispatch on}';

    protected $description = 'Run the full zone pipeline (download, extract, load, diff, candidates, cleanup)';

    public function handle(): int
    {
        $queue = (string) ($this->option('queue') ?: config('zones.queue'));
        $runId = (string) Str::uuid();

        DownloadZones::dispatch($runId)->onQueue($queue);

        $this->info("Pipeline queued. Run id: {$runId}");

        return self::SUCCESS;
    }
}
