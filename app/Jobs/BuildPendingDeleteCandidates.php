<?php

namespace App\Jobs;

use App\Services\Candidates\OwnerIndependence;
use App\Services\Candidates\Scorer;
use App\Services\Candidates\WeakerSearcher;
use App\Services\ClickHouse\ClickHouseClient;
use App\Services\TracksJobRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Build the candidates for the names that dropped on one UTC date.
 *
 * The source is the current PendingDelete state (status = dropped), not a
 * zone diff. The weaker-version search still runs against the shared corpus,
 * so the scoring rules, TLD comparison and watchlist are unchanged. Only the
 * dropped name's own TLD is excluded from its exact-match count.
 */
class BuildPendingDeleteCandidates implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksJobRun;

    public int $timeout = 0;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    private const INSERT_BATCH_SIZE = 1000;

    private readonly string $runId;

    /** @var array<string, true> tld + NUL + name already searched for this date */
    private array $scored = [];

    public function __construct(
        public readonly string $date,
        public readonly bool $rebuild = false,
    ) {
        $this->runId = (string) Str::uuid();
        $this->onQueue((string) config('pendingdelete.queue', 'zones'));
        $this->onConnection((string) config('pendingdelete.connection', 'zones'));
    }

    public function uniqueId(): string
    {
        return 'pendingdelete-candidates:'.$this->date;
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
            $today = now()->utc()->format('Y-m-d');

            $total = (int) $clickhouse->scalar(
                sprintf(
                    'SELECT count() AS total FROM %s.pendingdelete_names FINAL '
                    .'WHERE status = {status:String} AND toDate(drop_utc) = {d:Date}',
                    $clickhouse->database(),
                ),
                ['status' => 'dropped', 'd' => $this->date],
            );

            if ($total === 0) {
                $this->skipRun('No dropped domains for '.$this->date.' yet.');

                return;
            }

            $tlds = $this->corpusTlds($clickhouse, $today);

            if ($tlds === []) {
                $this->skipRun('The comparison corpus is empty; run zones:ch-migrate and wait for the first import.');

                return;
            }

            // A rerun replaces this date only. Historical candidate days and
            // the watchlist are never touched.
            if ($this->rebuild) {
                $this->deleteCandidatesFor($clickhouse, $this->date);
                $this->deleteScoredFor($clickhouse, $this->date);
            } else {
                // A 10-minute import only searches names that are new since
                // the previous run; names without weaker versions are tracked
                // too, so they are not searched again.
                $this->loadScored($clickhouse);
            }

            $result = $this->build($clickhouse, $searcher, $scorer, $ownership, $today, $tlds, $total);

            $this->finishRun($result['candidates'], sprintf(
                'Built %d candidate(s) from %s new dropped name(s) (%s already scored) in %d chunk(s) in %.1fs (%d TLDs in corpus)',
                $result['candidates'],
                number_format($result['searched']),
                number_format(max(0, $result['read'] - $result['searched'])),
                $result['chunks'],
                $result['seconds'],
                count($tlds),
            ));
        } catch (Throwable $exception) {
            $this->failRun($exception);

            throw $exception;
        }
    }

    /**
     * @param  array<int, string>  $tlds
     * @return array{read:int, searched:int, candidates:int, chunks:int, seconds:float}
     */
    private function build(
        ClickHouseClient $clickhouse,
        WeakerSearcher $searcher,
        Scorer $scorer,
        OwnerIndependence $ownership,
        string $today,
        array $tlds,
        int $total,
    ): array {
        $chunkSize = max(1, (int) config('zones.weaker.chunk_size', 5000));

        $read = 0;
        $searched = 0;
        $candidates = 0;
        $chunks = 0;
        $started = microtime(true);
        $scoredAt = now()->utc()->format('Y-m-d H:i:s.v');

        $tld = null;
        /** @var array<string, ?string> $chunk name => drop_utc */
        $chunk = [];

        $flush = function () use (
            &$tld,
            &$chunk,
            &$read,
            &$searched,
            &$candidates,
            &$chunks,
            $clickhouse,
            $searcher,
            $scorer,
            $ownership,
            $today,
            $tlds,
            $total,
            $scoredAt,
            $started,
        ): void {
            if ($tld === null || $chunk === []) {
                return;
            }

            $currentTld = $tld;
            $drops = $chunk;
            $allNames = array_keys($chunk);
            $chunk = [];

            $read += count($allNames);

            $names = array_values(array_filter(
                $allNames,
                fn (string $name): bool => ! isset($this->scored[$currentTld."\0".$name]),
            ));

            if ($names === []) {
                return;
            }

            $results = $searcher->findMany($names, $today, $tlds, $currentTld);
            $rows = [];
            $scoredRows = [];

            foreach ($names as $name) {
                $scoredRows[] = [
                    'event_date' => $this->date,
                    'tld' => $currentTld,
                    'name' => $name,
                    'scored_at' => $scoredAt,
                ];

                $result = $results[$name] ?? null;

                if ($result === null) {
                    continue;
                }

                $self = $name.'.'.$currentTld;
                $found = [];
                $types = [];

                foreach (array_values($result['weaker_found']) as $index => $domain) {
                    if ($domain === $self) {
                        continue;
                    }

                    $found[] = $domain;
                    $types[] = (string) ($result['weaker_types'][$index] ?? '');
                }

                if ($found === []) {
                    continue;
                }

                $result['weaker_found'] = $found;
                $result['weaker_types'] = $types;

                $rows[] = $this->candidate(
                    $name,
                    $currentTld,
                    $drops[$name] ?? null,
                    $this->date,
                    $result,
                    $scorer,
                    $ownership,
                );
            }

            foreach (array_chunk($rows, self::INSERT_BATCH_SIZE) as $batch) {
                $clickhouse->insertJsonEachRow($clickhouse->database().'.com_candidates', $batch);
            }

            foreach (array_chunk($scoredRows, self::INSERT_BATCH_SIZE) as $batch) {
                $clickhouse->insertJsonEachRow($clickhouse->database().'.pendingdelete_scored', $batch);
            }

            $searched += count($names);
            $candidates += count($rows);
            $chunks++;

            $this->updateRun([
                'rows' => $candidates,
                'bytes_done' => $read,
                'bytes_total' => $total,
                'message' => sprintf(
                    'Chunk %d: %s %s dropped, %s candidates (%.1fs)',
                    $chunks,
                    number_format(count($names)),
                    $currentTld,
                    number_format(count($rows)),
                    microtime(true) - $started,
                ),
            ]);

            Log::info('PendingDelete candidate chunk built', [
                'run_id' => $this->runId,
                'date' => $this->date,
                'tld' => $currentTld,
                'chunk' => $chunks,
                'read' => count($names),
                'candidates' => count($rows),
            ]);
        };

        $clickhouse->streamRows(
            sprintf(
                'SELECT tld, name, toString(drop_utc) AS drop_utc '
                .'FROM %s.pendingdelete_names FINAL '
                ."WHERE status = 'dropped' AND toDate(drop_utc) = %s "
                .'ORDER BY tld, name',
                $clickhouse->database(),
                $this->sqlString($this->date),
            ),
            function (array $row) use (&$tld, &$chunk, $chunkSize, $flush): void {
                $rowTld = strtolower((string) ($row['tld'] ?? ''));
                $name = strtolower((string) ($row['name'] ?? ''));

                if ($rowTld === '' || $name === '') {
                    return;
                }

                if ($tld !== null && $rowTld !== $tld) {
                    $flush();
                }

                $tld = $rowTld;
                $chunk[$name] = isset($row['drop_utc']) && $row['drop_utc'] !== ''
                    ? (string) $row['drop_utc']
                    : null;

                if (count($chunk) >= $chunkSize) {
                    $flush();
                }
            },
        );

        $flush();

        return [
            'read' => $read,
            'searched' => $searched,
            'candidates' => $candidates,
            'chunks' => $chunks,
            'seconds' => microtime(true) - $started,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function candidate(
        string $name,
        string $tld,
        ?string $dropUtc,
        string $date,
        array $result,
        Scorer $scorer,
        OwnerIndependence $ownership,
    ): array {
        $ownershipResult = $ownership->analyze($result['nameservers']);

        $tldCount = count(array_unique(array_map(
            fn (string $domain) => substr($domain, strrpos($domain, '.') + 1),
            $result['weaker_found'],
        )));

        $exactTldCount = (int) ($result['exact_tld_count'] ?? 0);

        $scored = $scorer->score(
            $name,
            $result['weaker_found'],
            $result['weaker_types'],
            $ownershipResult,
            $exactTldCount,
            (bool) ($result['same_nameservers'] ?? false),
        );

        return [
            'event_date' => $date,
            'tld' => $tld,
            'name' => $name,
            'status' => 'dropped',
            'drop_utc' => $dropUtc,
            'weaker_found' => array_values($result['weaker_found']),
            'weaker_types' => array_values($result['weaker_types']),
            'weaker_count' => count($result['weaker_found']),
            'tld_count' => min($tldCount, 255),
            'exact_tld_count' => min($exactTldCount, 65535),
            'is_short' => strlen($name) <= WeakerSearcher::SHORT_NAME_MAX_LENGTH ? 1 : 0,
            'score' => $scored['score'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function corpusTlds(ClickHouseClient $clickhouse, string $today): array
    {
        $rows = $clickhouse->rows(
            sprintf(
                'SELECT DISTINCT tld FROM %s.weaker_corpus '
                .'WHERE snapshot_date = {d:Date} AND tld != \'\' ORDER BY tld',
                $clickhouse->database(),
            ),
            ['d' => $today],
        );

        return array_values(array_filter(array_map(
            fn (array $row) => strtolower((string) ($row['tld'] ?? '')),
            $rows,
        )));
    }

    private function deleteCandidatesFor(ClickHouseClient $clickhouse, string $date): void
    {
        $clickhouse->execute(
            sprintf(
                'ALTER TABLE %s.com_candidates DELETE WHERE event_date = {d:Date} SETTINGS mutations_sync = 1',
                $clickhouse->database(),
            ),
            ['d' => $date],
        );
    }

    private function deleteScoredFor(ClickHouseClient $clickhouse, string $date): void
    {
        $clickhouse->execute(
            sprintf(
                'ALTER TABLE %s.pendingdelete_scored DELETE WHERE event_date = {d:Date} SETTINGS mutations_sync = 1',
                $clickhouse->database(),
            ),
            ['d' => $date],
        );
    }

    private function loadScored(ClickHouseClient $clickhouse): void
    {
        $rows = $clickhouse->rows(
            sprintf(
                'SELECT DISTINCT tld, name FROM %s.pendingdelete_scored WHERE event_date = {d:Date}',
                $clickhouse->database(),
            ),
            ['d' => $this->date],
        );

        foreach ($rows as $row) {
            $tld = strtolower((string) ($row['tld'] ?? ''));
            $name = strtolower((string) ($row['name'] ?? ''));

            if ($tld !== '' && $name !== '') {
                $this->scored[$tld."\0".$name] = true;
            }
        }
    }

    private function sqlString(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
