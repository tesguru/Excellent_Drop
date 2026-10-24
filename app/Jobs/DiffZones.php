<?php

namespace App\Jobs;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\TracksJobRun;
use App\Services\Zone\ZoneDiffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Step 4 of the chain: compare today's snapshot with yesterday's and record
 * the differences in zone_events.
 *
 * Day one has no previous snapshot, so the diff is skipped and the baseline
 * is simply stored.
 */
class DiffZones implements ShouldQueue
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

    public function handle(ClickHouseClient $clickhouse, ZoneDiffer $differ): void
    {
        $this->beginRun('diff', null);

        try {
            $today = now()->format('Y-m-d');
            $yesterday = now()->subDay()->format('Y-m-d');

            $tlds = $this->tldsWithSnapshot($clickhouse);

            if ($tlds === []) {
                $this->skipRun('No snapshots loaded for today yet.');

                return;
            }

            $totalEvents = 0;
            $messages = [];

            foreach ($tlds as $tld) {
                if (! $differ->hasSnapshot($tld, $yesterday)) {
                    $messages[] = $tld.': baseline stored (no previous snapshot)';

                    continue;
                }

                $result = $this->diffTld($differ, $tld, $today, $yesterday);

                $totalEvents += $result['removed'] + $result['added'];
                $messages[] = sprintf('%s: %d removed, %d added', $tld, $result['removed'], $result['added']);
            }

            $this->finishRun($totalEvents, implode(' | ', $messages));

            BuildComCandidates::dispatch($this->runId)->onQueue((string) config('zones.queue'));
        } catch (Throwable $exception) {
            $this->failRun($exception);

            throw $exception;
        }
    }

    /**
     * @return array{removed:int, added:int}
     */
    private function diffTld(ZoneDiffer $differ, string $tld, string $today, string $yesterday): array
    {
        return $differ->diff($tld, $today, $yesterday);
    }

    /**
     * @return array<int, string>
     */
    private function tldsWithSnapshot(ClickHouseClient $clickhouse): array
    {
        $rows = $clickhouse->rows(
            sprintf('SELECT DISTINCT tld FROM %s.zone_names WHERE snapshot_date = {d:Date}', $clickhouse->database()),
            ['d' => now()->format('Y-m-d')],
        );

        return array_values(array_filter(array_map(fn (array $row) => (string) ($row['tld'] ?? ''), $rows)));
    }
}
