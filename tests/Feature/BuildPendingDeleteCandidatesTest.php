<?php

namespace Tests\Feature;

use App\Jobs\BuildPendingDeleteCandidates;
use App\Models\JobRun;
use App\Services\Candidates\OwnerIndependence;
use App\Services\Candidates\Scorer;
use App\Services\Candidates\WeakerSearcher;
use App\Services\ClickHouse\ClickHouseClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildPendingDeleteCandidatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_candidates_per_tld_with_the_dropped_columns(): void
    {
        $clickhouse = $this->clickhouse();
        $searcher = $this->searcher();

        $job = new BuildPendingDeleteCandidates('2026-11-01');
        $job->handle($clickhouse, $searcher, app(Scorer::class), app(OwnerIndependence::class));

        $candidates = $clickhouse->rowsFor('zones_test.com_candidates');
        $this->assertCount(2, $candidates);
        $this->assertSame('com', $candidates[0]['tld']);
        $this->assertSame('alpha', $candidates[0]['name']);
        $this->assertSame('dropped', $candidates[0]['status']);
        $this->assertSame('2026-11-01', $candidates[0]['event_date']);
        $this->assertSame('2026-11-01 04:02:02', $candidates[0]['drop_utc']);
        $this->assertSame(['alpha-alt.net'], $candidates[0]['weaker_found']);
        $this->assertSame('net', $candidates[1]['tld']);

        $this->assertSame([['alpha'], 'com'], $searcher->calls[0]);
        $this->assertSame([['beta'], 'net'], $searcher->calls[1]);

        $scored = $clickhouse->rowsFor('zones_test.pendingdelete_scored');
        $this->assertCount(2, $scored);
        $this->assertSame('alpha', $scored[0]['name']);
        $this->assertSame('2026-11-01', $scored[0]['event_date']);
        $this->assertSame('dropped', $scored[0]['mode']);

        $this->assertFalse($clickhouse->hasCall('DELETE WHERE event_date'));

        $run = JobRun::query()->firstOrFail();
        $this->assertSame('candidates', $run->step);
        $this->assertSame(JobRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(2, $run->rows);
    }

    public function test_a_rebuild_clears_the_date_before_searching_again(): void
    {
        $clickhouse = $this->clickhouse();
        $searcher = $this->searcher();

        $job = new BuildPendingDeleteCandidates('2026-11-01', true);
        $job->handle($clickhouse, $searcher, app(Scorer::class), app(OwnerIndependence::class));

        $this->assertTrue($clickhouse->hasCall('ALTER TABLE zones_test.com_candidates DELETE'));
        $this->assertTrue($clickhouse->hasCall('ALTER TABLE zones_test.pendingdelete_scored DELETE'));
        $this->assertTrue($clickhouse->hasCall("AND status IN ('dropped', '')"));
        $this->assertTrue($clickhouse->hasCall("AND mode = 'dropped'"));
        $this->assertSame([['alpha'], 'com'], $searcher->calls[0]);
    }

    public function test_names_scored_on_an_earlier_run_are_skipped(): void
    {
        $clickhouse = $this->clickhouse(scored: [['tld' => 'com', 'name' => 'alpha']]);
        $searcher = $this->searcher();

        $job = new BuildPendingDeleteCandidates('2026-11-01');
        $job->handle($clickhouse, $searcher, app(Scorer::class), app(OwnerIndependence::class));

        $this->assertCount(1, $searcher->calls);
        $this->assertSame([['beta'], 'net'], $searcher->calls[0]);

        $candidates = $clickhouse->rowsFor('zones_test.com_candidates');
        $this->assertCount(1, $candidates);
        $this->assertSame('beta', $candidates[0]['name']);

        $scored = $clickhouse->rowsFor('zones_test.pendingdelete_scored');
        $this->assertCount(1, $scored);
        $this->assertSame('beta', $scored[0]['name']);
    }

    public function test_pending_mode_scores_the_pending_names_and_never_clears(): void
    {
        $clickhouse = $this->clickhouse();
        $searcher = $this->searcher();

        $job = new BuildPendingDeleteCandidates('2026-11-02', true, 'pending');
        $job->handle($clickhouse, $searcher, app(Scorer::class), app(OwnerIndependence::class));

        // Pending scoring is always incremental: even a rebuild only fills
        // what is missing, it never deletes a date.
        $this->assertFalse($clickhouse->hasCall('ALTER TABLE'));

        $candidates = $clickhouse->rowsFor('zones_test.com_candidates');
        $this->assertCount(2, $candidates);
        $this->assertSame('pending_delete', $candidates[0]['status']);
        $this->assertSame('2026-11-02', $candidates[0]['event_date']);
        $this->assertSame('2026-11-01 04:02:02', $candidates[0]['drop_utc']);

        $this->assertSame([['alpha'], 'com'], $searcher->calls[0]);
        $this->assertSame([['beta'], 'net'], $searcher->calls[1]);

        $scored = $clickhouse->rowsFor('zones_test.pendingdelete_scored');
        $this->assertCount(2, $scored);
        $this->assertSame('2026-11-02', $scored[0]['event_date']);
        $this->assertSame('pending', $scored[0]['mode']);
    }

    /**
     * @param  array<int, array<string, string>>  $scored
     */
    private function clickhouse(array $scored = []): ClickHouseClient
    {
        return new class($scored) extends ClickHouseClient
        {
            /** @var array<int, array{table:string, rows:array<int, array<string, mixed>>}> */
            public array $inserts = [];

            /** @var array<int, string> */
            public array $executes = [];

            public int $total = 2;

            /**
             * @param  array<int, array<string, string>>  $scored
             */
            public function __construct(private readonly array $scored)
            {
                parent::__construct(['host' => '127.0.0.1', 'port' => 9000]);
            }

            public function database(): string
            {
                return 'zones_test';
            }

            public function scalar(string $sql, array $params = []): mixed
            {
                return $this->total;
            }

            public function rows(string $sql, array $params = []): array
            {
                if (str_contains($sql, 'weaker_corpus')) {
                    return [['tld' => 'com'], ['tld' => 'net']];
                }

                if (str_contains($sql, 'pendingdelete_scored')) {
                    return $this->scored;
                }

                return [];
            }

            public function streamRows(string $sql, callable $onRow, array $settings = []): void
            {
                $onRow(['tld' => 'com', 'name' => 'alpha', 'drop_utc' => '2026-11-01 04:02:02']);
                $onRow(['tld' => 'net', 'name' => 'beta', 'drop_utc' => '2026-11-01 04:02:44']);
            }

            public function insertJsonEachRow(string $table, array $rows): void
            {
                $this->inserts[] = ['table' => $table, 'rows' => $rows];
            }

            public function execute(string $sql, array $params = []): void
            {
                $this->executes[] = $sql;
            }

            /**
             * @return array<int, array<string, mixed>>
             */
            public function rowsFor(string $table): array
            {
                $rows = [];

                foreach ($this->inserts as $insert) {
                    if ($insert['table'] === $table) {
                        $rows = array_merge($rows, $insert['rows']);
                    }
                }

                return $rows;
            }

            public function hasCall(string $needle): bool
            {
                foreach ($this->executes as $sql) {
                    if (str_contains($sql, $needle)) {
                        return true;
                    }
                }

                return false;
            }
        };
    }

    private function searcher(): WeakerSearcher
    {
        return new class extends WeakerSearcher
        {
            /** @var array<int, array{0:array<int, string>, 1:string}> */
            public array $calls = [];

            public function __construct() {}

            public function findMany(array $names, string $snapshotDate, array $tlds, string $excludeTld = 'com'): array
            {
                $this->calls[] = [array_values($names), $excludeTld];

                $results = [];

                foreach ($names as $name) {
                    $results[$name] = [
                        'weaker_found' => [$name.'-alt.net'],
                        'weaker_types' => ['hyphen'],
                        'details' => [],
                        'nameservers' => [],
                        'exact_tld_count' => 1,
                        'same_nameservers' => false,
                    ];
                }

                return $results;
            }
        };
    }
}
