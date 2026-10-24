<?php

namespace App\Jobs;

use App\Services\Candidates\OwnerIndependence;
use App\Services\Candidates\Scorer;
use App\Services\Candidates\WeakerSearcher;
use App\Services\ClickHouse\ClickHouseClient;
use App\Services\TracksJobRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step 5 of the chain: find the weaker versions of the .com names that were
 * removed today and score the result.
 *
 * Only today's removals are searched. An earlier day is never searched again,
 * and a rerun only replaces today's rows.
 */
class BuildComCandidates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksJobRun;

    public int $timeout = 0;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    private const INSERT_BATCH_SIZE = 1000;

    private const QUERY_SETTINGS = ['max_query_size' => 67108864];

    public function __construct(public readonly string $runId)
    {
        $this->onQueue((string) config('zones.queue'));
        $this->onConnection('zones');
    }

    public function runIdentifier(): string
    {
        return $this->runId;
    }

    public function handle(
        ClickHouseClient $clickhouse,
        WeakerSearcher $searcher,
        Scorer $scorer,
        OwnerIndependence $ownership,
    ): void {
        $this->beginRun('candidates', null);

        try {
            $today = now()->format('Y-m-d');

            $tlds = $this->tldsWithSnapshot($clickhouse, $today);

            if ($tlds === []) {
                $this->skipRun('No snapshots available for today.');

                return;
            }

            // A rerun replaces today's rows only. Older candidate days are
            // history and are never touched here.
            $this->deleteCandidatesFor($clickhouse, $today);

            $totalRemoved = $this->removedTodayCount($clickhouse, $today);

            if ($totalRemoved === 0) {
                $this->finishRun(0, 'No .com names removed today.');
            } else {
                $result = $this->build($clickhouse, $searcher, $scorer, $ownership, $today, $tlds, $totalRemoved);

                $this->finishRun($result['candidates'], sprintf(
                    'Built %d candidate(s) from %s removed name(s) in %d chunk(s) in %.1fs (%s restored, %s searched)',
                    $result['candidates'],
                    number_format($result['read']),
                    $result['chunks'],
                    $result['seconds'],
                    number_format($result['restored']),
                    number_format($result['searched']),
                ));
            }
        } catch (Throwable $exception) {
            $this->failRun($exception);

            throw $exception;
        }

        Cleanup::dispatch($this->runId)->onQueue((string) config('zones.queue'));
    }

    /**
     * @param  array<int, string>  $tlds
     * @return array{read:int, searched:int, restored:int, candidates:int, chunks:int, seconds:float}
     */
    private function build(
        ClickHouseClient $clickhouse,
        WeakerSearcher $searcher,
        Scorer $scorer,
        OwnerIndependence $ownership,
        string $today,
        array $tlds,
        int $totalRemoved,
    ): array {
        $chunkSize = max(1, (int) config('zones.weaker.chunk_size', 5000));

        $read = 0;
        $searched = 0;
        $restored = 0;
        $candidates = 0;
        $chunks = 0;
        $started = microtime(true);

        /** @var array<int, string> $chunk */
        $chunk = [];

        $flush = function () use (
            &$chunk,
            &$read,
            &$searched,
            &$restored,
            &$candidates,
            &$chunks,
            $clickhouse,
            $searcher,
            $scorer,
            $ownership,
            $today,
            $tlds,
            $totalRemoved,
        ): void {
            if ($chunk === []) {
                return;
            }

            $chunkStarted = microtime(true);
            $namesInChunk = count($chunk);

            $names = $this->withoutRestored($clickhouse, $chunk, $today);

            $results = $names === [] ? [] : $searcher->findMany($names, $today, $tlds);
            $rows = [];

            foreach ($names as $name) {
                $result = $results[$name] ?? null;

                if ($result === null || $result['weaker_found'] === []) {
                    continue;
                }

                $rows[] = $this->candidate($name, $today, $result, $scorer, $ownership);
            }

            foreach (array_chunk($rows, self::INSERT_BATCH_SIZE) as $batch) {
                $clickhouse->insertJsonEachRow($clickhouse->database().'.com_candidates', $batch);
            }

            $read += $namesInChunk;
            $searched += count($names);
            $restored += $namesInChunk - count($names);
            $candidates += count($rows);
            $chunks++;

            $chunk = [];

            $seconds = microtime(true) - $chunkStarted;

            $this->updateRun([
                'rows' => $candidates,
                'bytes_done' => $read,
                'bytes_total' => $totalRemoved,
                'message' => sprintf(
                    'Chunk %d: %s read, %s searched, %s candidates (%.1fs)',
                    $chunks,
                    number_format($namesInChunk),
                    number_format(count($names)),
                    number_format(count($rows)),
                    $seconds,
                ),
            ]);

            Log::info('Candidate chunk built', [
                'run_id' => $this->runId,
                'chunk' => $chunks,
                'read' => $namesInChunk,
                'searched' => count($names),
                'candidates' => count($rows),
                'seconds' => round($seconds, 2),
            ]);
        };

        $clickhouse->streamRows(
            $this->removedTodaySql($clickhouse, $today),
            function (array $row) use (&$chunk, $chunkSize, $flush): void {
                $name = (string) ($row['name'] ?? '');

                if ($name === '') {
                    return;
                }

                $chunk[] = $name;

                if (count($chunk) >= $chunkSize) {
                    $flush();
                }
            },
        );

        $flush();

        return [
            'read' => $read,
            'searched' => $searched,
            'restored' => $restored,
            'candidates' => $candidates,
            'chunks' => $chunks,
            'seconds' => microtime(true) - $started,
        ];
    }

    /**
     * A name that is back in today's .com snapshot was restored, not dropped,
     * so it never gets a candidate.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function withoutRestored(ClickHouseClient $clickhouse, array $names, string $today): array
    {
        if ($names === []) {
            return [];
        }

        $rows = $clickhouse->rowsFromSql(
            sprintf(
                'SELECT name FROM %s.zone_names '
                .'WHERE snapshot_date = %s AND tld = \'com\' AND name IN %s',
                $clickhouse->database(),
                $this->sqlString($today),
                $this->sqlArray($names),
            ),
            self::QUERY_SETTINGS,
        );

        $present = [];

        foreach ($rows as $row) {
            $present[(string) ($row['name'] ?? '')] = true;
        }

        return array_values(array_filter(
            $names,
            fn (string $name) => ! isset($present[$name]),
        ));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function candidate(
        string $name,
        string $eventDate,
        array $result,
        Scorer $scorer,
        OwnerIndependence $ownership,
    ): array {
        // Owner independence comes from the nameservers published in the
        // zone snapshots. Missing data is unknown, never a reason to hide a
        // candidate.
        $ownershipResult = $ownership->analyze($result['nameservers']);

        $tldCount = count(array_unique(array_map(
            fn (string $domain) => substr($domain, strrpos($domain, '.') + 1),
            $result['weaker_found'],
        )));

        $scored = $scorer->score(
            $name,
            $result['weaker_found'],
            $result['weaker_types'],
            $ownershipResult,
            $tldCount,
        );

        return [
            'event_date' => $eventDate,
            'name' => $name,
            'weaker_found' => array_values($result['weaker_found']),
            'weaker_types' => array_values($result['weaker_types']),
            'weaker_count' => count($result['weaker_found']),
            'tld_count' => min($tldCount, 255),
            'is_short' => strlen($name) <= WeakerSearcher::SHORT_NAME_MAX_LENGTH ? 1 : 0,
            'score' => $scored['score'],
        ];
    }

    private function deleteCandidatesFor(ClickHouseClient $clickhouse, string $date): void
    {
        $clickhouse->execute(
            sprintf(
                'ALTER TABLE %s.com_candidates DELETE WHERE event_date = {d:Date} SETTINGS mutations_sync = 2',
                $clickhouse->database(),
            ),
            ['d' => $date],
        );
    }

    private function removedTodayCount(ClickHouseClient $clickhouse, string $today): int
    {
        return (int) $clickhouse->scalar(
            sprintf(
                'SELECT count() AS total FROM ('
                .'SELECT DISTINCT name FROM %s.zone_events '
                .'WHERE tld = \'com\' AND event = \'removed\' AND event_date = %s'
                .')',
                $clickhouse->database(),
                $this->sqlString($today),
            ),
        );
    }

    /**
     * Stream only today's removals. No earlier day is ever searched again.
     */
    private function removedTodaySql(ClickHouseClient $clickhouse, string $today): string
    {
        return sprintf(
            'SELECT DISTINCT name FROM %s.zone_events '
            .'WHERE tld = \'com\' AND event = \'removed\' AND event_date = %s '
            .'ORDER BY name',
            $clickhouse->database(),
            $this->sqlString($today),
        );
    }

    /**
     * @return array<int, string>
     */
    private function tldsWithSnapshot(ClickHouseClient $clickhouse, string $date): array
    {
        $rows = $clickhouse->rows(
            sprintf('SELECT DISTINCT tld FROM %s.zone_names WHERE snapshot_date = {d:Date}', $clickhouse->database()),
            ['d' => $date],
        );

        return array_values(array_filter(array_map(fn (array $row) => (string) ($row['tld'] ?? ''), $rows)));
    }

    /**
     * @param  array<int, string>  $values
     */
    private function sqlArray(array $values): string
    {
        return '['.implode(',', array_map(fn (string $value) => $this->sqlString($value), $values)).']';
    }

    private function sqlString(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
