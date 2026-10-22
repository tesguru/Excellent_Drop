<?php

namespace App\Jobs;

use App\Models\ZoneTld;
use App\Services\TracksJobRun;
use App\Services\Zone\ZoneLoader;
use App\Services\ZonePaths;
use App\Services\ZoneRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Step 3 of the chain: load the extracted names into ClickHouse.
 */
class LoadSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksJobRun;

    public int $timeout = 0;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    public function __construct(
        public readonly string $runId,
        public readonly string $tld,
    ) {
        $this->onQueue((string) config('zones.queue'));
    }

    public function runIdentifier(): string
    {
        return $this->runId;
    }

    public function handle(ZoneLoader $loader, ZonePaths $paths, ZoneRegistry $registry): void
    {
        $this->beginRun('load', $this->tld);

        try {
            $namesPath = $paths->namesFile($this->tld);

            if (! is_file($namesPath)) {
                $this->skipRun('No extracted names found for '.$this->tld);

                return;
            }

            $snapshotDate = now()->format('Y-m-d');

            $rows = $loader->load($this->tld, $namesPath, $snapshotDate);

            $registry->mark($this->tld, [
                'status' => 'loaded',
                'rows' => $rows,
            ]);

            $this->finishRun($rows, sprintf('Loaded %s names into ClickHouse', number_format($rows)));

            $this->dispatchDiffWhenReady();
        } catch (Throwable $exception) {
            $this->failRun($exception);

            throw $exception;
        }
    }

    /**
     * The diff can only run once every downloaded zone has been loaded.
     */
    private function dispatchDiffWhenReady(): void
    {
        $today = now()->format('Y-m-d');

        $downloaded = ZoneTld::whereDate('last_download_at', $today)
            ->whereIn('status', ['downloaded', 'loaded'])
            ->count();

        $loaded = ZoneTld::whereDate('last_download_at', $today)
            ->where('status', 'loaded')
            ->count();

        if ($downloaded === 0 || $loaded < $downloaded) {
            return;
        }

        $lock = cache()->lock('zones:diff-dispatch', 300);

        if (! $lock->get()) {
            return;
        }

        try {
            DiffZones::dispatch($this->runId)->onQueue((string) config('zones.queue'));
        } finally {
            $lock->release();
        }
    }
}
