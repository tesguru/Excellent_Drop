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
 * Step 5 of the chain: find the weaker versions of every removed .com name
 * and score the result.
 */
class BuildComCandidates implements ShouldQueue
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

            $removed = $this->removedComNames($clickhouse, $today);
            $alreadyBuilt = $this->builtCandidateNames($clickhouse, $today);

            $candidates = [];

            foreach ($removed as $name => $eventDate) {
                if ($eventDate !== $today && isset($alreadyBuilt[$eventDate][$name])) {
                    continue;
                }

                $candidate = $this->buildCandidate($name, $eventDate, $tlds, $searcher, $scorer, $ownership);

                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }

            if ($candidates === []) {
                $this->finishRun(0, 'No .com candidates with weaker versions today.');
            } else {
                $this->replaceCandidates($clickhouse, $candidates);

                $this->finishRun(count($candidates), sprintf(
                    'Built %d candidate(s) with weaker versions',
                    count($candidates),
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
     * @return array<string, mixed>|null
     */
    private function buildCandidate(
        string $name,
        string $eventDate,
        array $tlds,
        WeakerSearcher $searcher,
        Scorer $scorer,
        OwnerIndependence $ownership,
    ): ?array {
        $result = $searcher->find($name, $eventDate, $tlds);

        if ($result['weaker_found'] === []) {
            return null;
        }

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

        Log::info('Candidate built', [
            'name' => $name,
            'weaker' => count($result['weaker_found']),
            'tlds' => $tldCount,
            'score' => $scored['score'],
        ]);

        return [
            'event_date' => $eventDate,
            'name' => $name,
            'weaker_found' => $result['weaker_found'],
            'weaker_types' => $result['weaker_types'],
            'weaker_count' => count($result['weaker_found']),
            'tld_count' => $tldCount,
            'is_short' => strlen($name) <= WeakerSearcher::SHORT_NAME_MAX_LENGTH,
            'score' => $scored['score'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function replaceCandidates(ClickHouseClient $clickhouse, array $candidates): void
    {
        $dates = array_values(array_unique(array_map(fn (array $c) => (string) $c['event_date'], $candidates)));

        foreach ($dates as $date) {
            $clickhouse->execute(
                sprintf(
                    'ALTER TABLE %s.com_candidates DELETE WHERE event_date = {d:Date} SETTINGS mutations_sync = 2',
                    $clickhouse->database(),
                ),
                ['d' => $date],
            );
        }

        foreach ($candidates as $candidate) {
            $clickhouse->execute(
                sprintf(
                    'INSERT INTO %s.com_candidates '
                    .'(event_date, name, weaker_found, weaker_types, weaker_count, tld_count, is_short, score) '
                    .'VALUES ({d:Date}, {n:String}, {wf:Array(String)}, {wt:Array(String)}, {wc:UInt32}, {tc:UInt8}, {short:UInt8}, {s:Float32})',
                    $clickhouse->database(),
                ),
                [
                    'd' => $candidate['event_date'],
                    'n' => $candidate['name'],
                    'wf' => $candidate['weaker_found'],
                    'wt' => $candidate['weaker_types'],
                    'wc' => $candidate['weaker_count'],
                    'tc' => min($candidate['tld_count'], 255),
                    'short' => $candidate['is_short'] ? 1 : 0,
                    's' => $candidate['score'],
                ],
            );
        }
    }

    /**
     * Removed .com names for the last seven days, keyed by name.
     *
     * @return array<string, string> name => event date
     */
    private function removedComNames(ClickHouseClient $clickhouse, string $today): array
    {
        $from = now()->subDays(7)->format('Y-m-d');

        $rows = $clickhouse->rows(
            sprintf(
                'SELECT name, event_date FROM %s.zone_events '
                .'WHERE tld = {tld:String} AND event = {event:String} AND event_date >= {from:Date} AND event_date <= {to:Date} '
                .'ORDER BY event_date, name',
                $clickhouse->database(),
            ),
            ['tld' => 'com', 'event' => 'removed', 'from' => $from, 'to' => $today],
        );

        $names = [];

        foreach ($rows as $row) {
            $names[(string) ($row['name'] ?? '')] = (string) ($row['event_date'] ?? '');
        }

        return $names;
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function builtCandidateNames(ClickHouseClient $clickhouse, string $today): array
    {
        $from = now()->subDays(7)->format('Y-m-d');

        $rows = $clickhouse->rows(
            sprintf(
                'SELECT name, event_date FROM %s.com_candidates WHERE event_date >= {from:Date} AND event_date <= {to:Date}',
                $clickhouse->database(),
            ),
            ['from' => $from, 'to' => $today],
        );

        $built = [];

        foreach ($rows as $row) {
            $date = (string) ($row['event_date'] ?? '');
            $built[$date][(string) ($row['name'] ?? '')] = true;
        }

        return $built;
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
}
