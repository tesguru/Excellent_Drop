<?php

namespace App\Jobs;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\TracksJobRun;
use App\Services\ZonePaths;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step 7 of the chain: drop snapshots that are out of retention and remove
 * the raw zone files.
 */
class Cleanup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksJobRun;

    public int $timeout = 0;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    public function __construct(public readonly string $runId)
    {
        $this->onQueue((string) config('zones.queue'));
    }

    public function runIdentifier(): string
    {
        return $this->runId;
    }

    public function handle(ClickHouseClient $clickhouse, ZonePaths $paths): void
    {
        $this->beginRun('cleanup', null);

        try {
            $dropped = $this->dropOldPartitions($clickhouse);
            $removedFiles = $this->removeRawFiles($paths);

            $this->finishRun($dropped, sprintf(
                'Dropped %d old partition(s), removed %d raw file(s)',
                $dropped,
                $removedFiles,
            ));
        } catch (Throwable $exception) {
            $this->failRun($exception);

            throw $exception;
        }
    }

    private function dropOldPartitions(ClickHouseClient $clickhouse): int
    {
        $retention = (int) config('zones.snapshot_retention_days', 45);

        $cutoff = now()->subDays($retention)->format('Y-m-d');

        $rows = $clickhouse->rows(
            sprintf(
                'SELECT DISTINCT snapshot_date FROM %s.zone_names WHERE snapshot_date < {d:Date} ORDER BY snapshot_date',
                $clickhouse->database(),
            ),
            ['d' => $cutoff],
        );

        $dropped = 0;

        foreach ($rows as $row) {
            $date = (string) ($row['snapshot_date'] ?? '');

            if ($date === '') {
                continue;
            }

            $clickhouse->execute(
                sprintf('ALTER TABLE %s.zone_names DROP PARTITION {p:String}', $clickhouse->database()),
                ['p' => $date],
            );

            $dropped++;
        }

        if ($dropped > 0) {
            Log::info('Dropped old zone snapshots', ['partitions' => $dropped, 'cutoff' => $cutoff]);
        }

        return $dropped;
    }

    private function removeRawFiles(ZonePaths $paths): int
    {
        $keepRaw = (bool) config('zones.keep_raw', false);

        $removed = 0;

        foreach (glob($paths->dir().'/*.names.tsv') ?: [] as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        if (! $keepRaw) {
            foreach (glob($paths->dir().'/*.zone.gz') ?: [] as $file) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }
}
