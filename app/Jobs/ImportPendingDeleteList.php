<?php

namespace App\Jobs;

use App\Services\PendingDelete\PendingDeleteClient;
use App\Services\PendingDelete\PendingDeleteImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Download one PendingDelete.net list (dropping today, pending, dropped
 * yesterday) for every TLD and import it.
 *
 * Each file is independent: one malformed file fails its own dataset_imports
 * row without touching the others. The candidate build is dispatched after
 * the successful files, so a partial list still produces today's candidates.
 */
class ImportPendingDeleteList implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $list,
        public readonly bool $force = false,
    ) {
        $this->onQueue((string) config('pendingdelete.queue', 'zones'));
        $this->onConnection((string) config('pendingdelete.connection', 'zones'));
    }

    public function uniqueId(): string
    {
        return 'pendingdelete-import:'.$this->list;
    }

    public function handle(PendingDeleteClient $client, PendingDeleteImporter $importer): void
    {
        $manifest = $client->manifest();
        $files = $client->filesFor($manifest, $this->list);

        if ($files === []) {
            throw new \RuntimeException('The manifest has no files for list "'.$this->list.'".');
        }

        $dates = [];
        $imported = 0;
        $failed = 0;

        foreach ($files as $file) {
            $tld = (string) ($file['tld'] ?? '?');

            try {
                foreach ($importer->import($file, $manifest, $this->force) as $date) {
                    $dates[$date] = true;
                }

                $imported++;
            } catch (Throwable $exception) {
                $failed++;

                Log::error('PendingDelete dataset import failed', [
                    'list' => $this->list,
                    'tld' => $tld,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        // The pending backlog is not dropped yet, so it does not create
        // candidates. The other two lists know which day the names drop.
        if ($this->list !== 'pending') {
            foreach (array_keys($dates) as $date) {
                BuildPendingDeleteCandidates::dispatch((string) $date, $this->force);
            }
        }

        Log::info('PendingDelete list imported', [
            'list' => $this->list,
            'built_utc' => $manifest['built_utc'] ?? null,
            'files' => count($files),
            'imported' => $imported,
            'failed' => $failed,
            'drop_dates' => array_keys($dates),
        ]);

        if ($failed > 0) {
            throw new \RuntimeException(sprintf(
                '%d of %d %s datasets failed. See dataset_imports and the log.',
                $failed,
                count($files),
                $this->list,
            ));
        }
    }
}
