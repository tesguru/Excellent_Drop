<?php

namespace App\Jobs;

use App\Models\RdapStatus;
use App\Models\WeakerCheck;
use App\Services\Candidates\OwnerIndependence;
use App\Services\Candidates\Scorer;
use App\Services\Candidates\WeakerSearcher;
use App\Services\ClickHouse\ClickHouseClient;
use App\Services\TracksJobRun;
use App\Services\Weaker\WeakerSiteChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
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
        WeakerSiteChecker $siteChecker,
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

                $candidate = $this->buildCandidate($name, $eventDate, $tlds, $searcher, $scorer, $ownership, $siteChecker);

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

        RdapCheck::dispatch($this->runId)->onQueue((string) config('zones.queue'));
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
        WeakerSiteChecker $siteChecker,
    ): ?array {
        $result = $searcher->find($name, $eventDate, $tlds);

        if ($result['weaker_found'] === []) {
            return null;
        }

        $siteChecks = $this->checkSites($result['weaker_found'], $siteChecker);
        $nameservers = $this->nameservers($result['weaker_found'], $siteChecks);

        $ownershipResult = $ownership->analyze($nameservers);

        $tldCount = count(array_unique(array_map(
            fn (string $domain) => substr($domain, strrpos($domain, '.') + 1),
            $result['weaker_found'],
        )));

        $scored = $scorer->score(
            $name,
            $result['weaker_found'],
            $result['weaker_types'],
            $siteChecks,
            $ownershipResult,
            $tldCount,
        );

        $this->rememberStatus($name, $eventDate);

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
     * @param  array<int, string>  $domains
     * @return array<string, array{http_status:int|null, has_contact_page:bool}>
     */
    private function checkSites(array $domains, WeakerSiteChecker $checker): array
    {
        $maxChecks = (int) config('zones.weaker.max_site_checks', 8);
        $checks = [];

        foreach (array_slice($domains, 0, $maxChecks) as $domain) {
            $existing = WeakerCheck::where('domain', $domain)->first();

            if ($existing !== null && $existing->checked_at !== null && $existing->checked_at->gt(now()->subDays(7))) {
                $checks[$domain] = [
                    'http_status' => $existing->http_status,
                    'has_contact_page' => $existing->has_contact_page,
                ];

                continue;
            }

            $result = $checker->check($domain);

            WeakerCheck::updateOrCreate(
                ['domain' => $domain],
                [
                    'http_status' => $result['http_status'],
                    'has_contact_page' => $result['has_contact_page'],
                    'title' => $result['title'],
                    'nameservers' => $result['nameservers'],
                    'checked_at' => now(),
                ],
            );

            $checks[$domain] = [
                'http_status' => $result['http_status'],
                'has_contact_page' => $result['has_contact_page'],
            ];
        }

        return $checks;
    }

    /**
     * @param  array<int, string>  $domains
     * @param  array<string, array{http_status:int|null, has_contact_page:bool}>  $siteChecks
     * @return array<string, array<int, string>>
     */
    private function nameservers(array $domains, array $siteChecks): array
    {
        $nameservers = [];

        foreach ($domains as $domain) {
            $check = WeakerCheck::where('domain', $domain)->first();

            if ($check !== null && is_array($check->nameservers) && $check->nameservers !== []) {
                $nameservers[$domain] = $check->nameservers;

                continue;
            }

            $nameservers[$domain] = [];
        }

        return $nameservers;
    }

    private function rememberStatus(string $name, string $eventDate): void
    {
        $domain = $name.'.com';

        $redemptionDays = (int) config('zones.drop.redemption_days', 30);
        $pendingDeleteDays = (int) config('zones.drop.pending_delete_days', 5);

        $predictedDrop = Carbon::parse($eventDate)
            ->addDays($redemptionDays + $pendingDeleteDays)
            ->format('Y-m-d');

        $status = RdapStatus::firstOrNew(['domain' => $domain]);

        $status->fill([
            'status' => $status->status ?? 'removed',
            'predicted_drop_date' => $status->predicted_drop_date ?? $predictedDrop,
        ]);

        $status->save();
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
