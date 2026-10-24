<?php

namespace Tests\Feature;

use App\Jobs\BuildComCandidates;
use App\Jobs\Cleanup;
use App\Jobs\DiffZones;
use App\Models\JobRun;
use App\Services\CandidateRepository;
use App\Services\Candidates\OwnerIndependence;
use App\Services\Candidates\Scorer;
use App\Services\Candidates\WeakerSearcher;
use App\Services\ClickHouse\ClickHouseClient;
use App\Services\Zone\ZoneDiffer;
use App\Services\Zone\ZoneLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End to end test of the pipeline against a real ClickHouse server.
 *
 * It runs in its own database (zones_test) so the production data is never
 * touched. The test is skipped when ClickHouse is not reachable.
 */
class PipelineTest extends TestCase
{
    use RefreshDatabase;

    private const DATABASE = 'zones_test';

    private ClickHouseClient $clickhouse;

    protected function setUp(): void
    {
        parent::setUp();

        config(['zones.clickhouse.database' => self::DATABASE]);

        $this->clickhouse = new ClickHouseClient(config('zones.clickhouse'));

        try {
            $this->clickhouse->scalar('SELECT 1');
        } catch (\Throwable $exception) {
            $this->markTestSkipped('ClickHouse is not available: '.$exception->getMessage());
        }

        $this->applySchema();
        $this->truncate();

        Queue::fake();
    }

    public function test_a_removed_name_with_a_weaker_version_becomes_a_candidate(): void
    {
        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        $loader = new ZoneLoader($this->clickhouse, self::DATABASE);

        $loader->load('com', $this->namesFile([
            'acmeplumbing',
            'goneone',
        ]), $yesterday);

        $loader->load('com', $this->namesFile([
            'newname',
        ]), $today);

        $loader->load('net', $this->namesFile([
            'acme-plumbing',
            'acmeplumbing',
            'getacmeplumbing',
        ]), $today);

        $differ = new ZoneDiffer($this->clickhouse, self::DATABASE);

        $counts = $differ->diff('com', $today, $yesterday);

        $this->assertSame(2, $counts['removed']);
        $this->assertSame(1, $counts['added']);

        $events = $this->clickhouse->rows(
            'SELECT name, event FROM '.self::DATABASE.'.zone_events WHERE event_date = {d:Date} AND tld = {tld:String} ORDER BY name',
            ['d' => $today, 'tld' => 'com'],
        );

        $this->assertSame([
            ['name' => 'acmeplumbing', 'event' => 'removed'],
            ['name' => 'goneone', 'event' => 'removed'],
            ['name' => 'newname', 'event' => 'added'],
        ], $events);

        // The candidate step needs the searcher and the scorer, both of which
        // read from the same test database.
        $this->buildCandidates();

        $candidates = $this->clickhouse->rows(
            'SELECT name, weaker_found, weaker_types, weaker_count, tld_count, exact_tld_count, is_short, score FROM '.self::DATABASE.'.com_candidates ORDER BY name',
        );

        $this->assertCount(1, $candidates);
        $this->assertSame('acmeplumbing', $candidates[0]['name']);
        $this->assertSame(0, (int) $candidates[0]['is_short']);
        $this->assertSame(1, (int) $candidates[0]['exact_tld_count']);
        $this->assertContains('acme-plumbing.net', $candidates[0]['weaker_found']);
        $this->assertContains('acmeplumbing.net', $candidates[0]['weaker_found']);
        $this->assertContains('getacmeplumbing.net', $candidates[0]['weaker_found']);

        // The signals are a parallel array: the hyphen version carries the
        // hyphen signal, the plain version the other_tld signal.
        $signals = [];

        foreach ($candidates[0]['weaker_found'] as $index => $domain) {
            $signals[$domain] = $candidates[0]['weaker_types'][$index];
        }

        $this->assertStringContainsString('hyphen', $signals['acme-plumbing.net']);
        $this->assertStringContainsString('other_tld', $signals['acmeplumbing.net']);
        $this->assertStringContainsString('ends_with', $signals['getacmeplumbing.net']);

        $this->assertGreaterThan(0, (float) $candidates[0]['score']);
    }

    public function test_exact_tld_count_covers_every_other_tld_and_the_filter_uses_it(): void
    {
        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        $loader = new ZoneLoader($this->clickhouse, self::DATABASE);

        $loader->load('com', $this->namesFile(['acmeplumbing', 'goneone']), $yesterday);
        $loader->load('com', $this->namesFile(['acme-plumbing', 'getacmeplumbing', 'newname']), $today);
        $loader->load('net', $this->namesFile(['acmeplumbing', 'acme-plumbing', 'goneone']), $today);
        $loader->load('org', $this->namesFile(['acmeplumbing']), $today);
        $loader->load('io', $this->namesFile(['acmeplumbing', 'getacmeplumbing']), $today);

        (new ZoneDiffer($this->clickhouse, self::DATABASE))->diff('com', $today, $yesterday);

        $this->buildCandidates();

        $candidate = $this->clickhouse->row(
            'SELECT name, weaker_found, exact_tld_count, score FROM '.self::DATABASE.'.com_candidates WHERE name = {n:String}',
            ['n' => 'acmeplumbing'],
        );

        $this->assertNotNull($candidate);

        // net, org and io carry the exact name; .com does not count.
        $this->assertSame(3, (int) $candidate['exact_tld_count']);

        // The search includes .com variants, but not the dropped domain.
        $this->assertContains('acme-plumbing.com', $candidate['weaker_found']);
        $this->assertContains('getacmeplumbing.com', $candidate['weaker_found']);
        $this->assertNotContains('acmeplumbing.com', $candidate['weaker_found']);

        $other = $this->clickhouse->row(
            'SELECT name, exact_tld_count FROM '.self::DATABASE.'.com_candidates WHERE name = {n:String}',
            ['n' => 'goneone'],
        );

        $this->assertNotNull($other);
        $this->assertSame(1, (int) $other['exact_tld_count']);

        $repository = new CandidateRepository($this->clickhouse, self::DATABASE);

        $this->assertCount(2, $repository->forDate($today));

        $filtered = $repository->forDate($today, 500, false, true);

        $this->assertCount(1, $filtered);
        $this->assertSame('acmeplumbing', $filtered[0]['name']);
    }

    public function test_a_three_letter_name_does_not_flood_the_candidate_with_matches(): void
    {
        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        $loader = new ZoneLoader($this->clickhouse, self::DATABASE);

        $loader->load('com', $this->namesFile(['abc']), $yesterday);
        $loader->load('com', $this->namesFile([]), $today);

        // Thirty names that add exactly two characters, far more than a short
        // name is allowed to keep.
        $flood = ['abc', 'a-bc', 'ab-c', 'abcs', 'abcx', 'xabc', 'abcdef', 'xyzabc'];

        foreach (['a', 'b', 'c'] as $first) {
            foreach (range('a', 'j') as $second) {
                $flood[] = 'abc'.$first.$second;
            }
        }

        $loader->load('net', $this->namesFile($flood), $today);

        (new ZoneDiffer($this->clickhouse, self::DATABASE))->diff('com', $today, $yesterday);

        $this->buildCandidates();

        $candidate = $this->clickhouse->row(
            'SELECT name, weaker_found, weaker_count, is_short FROM '.self::DATABASE.'.com_candidates WHERE name = {n:String}',
            ['n' => 'abc'],
        );

        $this->assertNotNull($candidate);
        $this->assertSame(1, (int) $candidate['is_short']);

        // A short name keeps at most 20 weaker versions, even when the zone
        // has dozens of prefix matches.
        $this->assertSame(20, (int) $candidate['weaker_count']);
        $this->assertCount(20, $candidate['weaker_found']);

        // The closest matches survive the cap.
        $this->assertContains('abc.net', $candidate['weaker_found']);
        $this->assertContains('a-bc.net', $candidate['weaker_found']);
        $this->assertContains('abcs.net', $candidate['weaker_found']);
        $this->assertContains('abcx.net', $candidate['weaker_found']);
        $this->assertContains('xabc.net', $candidate['weaker_found']);

        // Names that add three or more characters are only allowed for names
        // of five characters or longer.
        $this->assertNotContains('abcdef.net', $candidate['weaker_found']);
        $this->assertNotContains('xyzabc.net', $candidate['weaker_found']);
    }

    public function test_a_four_letter_name_uses_the_restricted_rules(): void
    {
        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        $loader = new ZoneLoader($this->clickhouse, self::DATABASE);

        $loader->load('com', $this->namesFile(['acme']), $yesterday);
        $loader->load('com', $this->namesFile([]), $today);

        $loader->load('net', $this->namesFile([
            'acme',
            'a-cme',
            'ac-me',
            'acm-e',
            'acmes',
            'acmex',
            'xacme',
            'acmexy',
            'xyacme',
            'acmefoo',
            'fooacme',
        ]), $today);

        (new ZoneDiffer($this->clickhouse, self::DATABASE))->diff('com', $today, $yesterday);

        $this->buildCandidates();

        $candidate = $this->clickhouse->row(
            'SELECT name, weaker_found, weaker_types, is_short FROM '.self::DATABASE.'.com_candidates WHERE name = {n:String}',
            ['n' => 'acme'],
        );

        $this->assertNotNull($candidate);
        $this->assertSame(1, (int) $candidate['is_short']);

        // The unrestricted rules still run.
        $this->assertContains('acme.net', $candidate['weaker_found']);
        $this->assertContains('a-cme.net', $candidate['weaker_found']);
        $this->assertContains('acmes.net', $candidate['weaker_found']);

        // Prefix and suffix matches may only add one or two characters.
        $this->assertContains('acmex.net', $candidate['weaker_found']);
        $this->assertContains('xacme.net', $candidate['weaker_found']);
        $this->assertContains('acmexy.net', $candidate['weaker_found']);
        $this->assertContains('xyacme.net', $candidate['weaker_found']);

        $this->assertNotContains('acmefoo.net', $candidate['weaker_found']);
        $this->assertNotContains('fooacme.net', $candidate['weaker_found']);
    }

    public function test_a_restored_name_is_not_searched(): void
    {
        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        $loader = new ZoneLoader($this->clickhouse, self::DATABASE);

        $loader->load('com', $this->namesFile(['acmeplumbing']), $yesterday);
        $loader->load('com', $this->namesFile([]), $today);
        $loader->load('net', $this->namesFile(['acme-plumbing']), $today);

        (new ZoneDiffer($this->clickhouse, self::DATABASE))->diff('com', $today, $yesterday);

        // The name reappears in the .com snapshot after the diff ran.
        $loader->load('com', $this->namesFile(['acmeplumbing']), $today);

        $this->buildCandidates();

        $count = (int) $this->clickhouse->scalar(
            'SELECT count() FROM '.self::DATABASE.'.com_candidates WHERE name = {n:String}',
            ['n' => 'acmeplumbing'],
        );

        $this->assertSame(0, $count);
    }

    public function test_candidates_from_older_days_are_not_deleted(): void
    {
        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        $this->clickhouse->insertJsonEachRow(self::DATABASE.'.com_candidates', [[
            'event_date' => $yesterday,
            'name' => 'oldcandidate',
            'weaker_found' => ['oldcandidate.net'],
            'weaker_types' => ['other_tld'],
            'weaker_count' => 1,
            'tld_count' => 1,
            'exact_tld_count' => 1,
            'is_short' => 0,
            'score' => 10.0,
        ]]);

        $loader = new ZoneLoader($this->clickhouse, self::DATABASE);

        $loader->load('com', $this->namesFile(['acmeplumbing']), $yesterday);
        $loader->load('com', $this->namesFile([]), $today);
        $loader->load('net', $this->namesFile(['acmeplumbing']), $today);

        (new ZoneDiffer($this->clickhouse, self::DATABASE))->diff('com', $today, $yesterday);

        $this->buildCandidates();

        $rows = $this->clickhouse->rows(
            'SELECT event_date, name FROM '.self::DATABASE.'.com_candidates ORDER BY event_date, name',
        );

        $this->assertSame([
            ['event_date' => $yesterday, 'name' => 'oldcandidate'],
            ['event_date' => $today, 'name' => 'acmeplumbing'],
        ], $rows);
    }

    public function test_only_today_is_searched_and_chunk_progress_is_recorded(): void
    {
        config(['zones.weaker.chunk_size' => 2]);

        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        $loader = new ZoneLoader($this->clickhouse, self::DATABASE);

        $loader->load('com', $this->namesFile(['alpha', 'bravo', 'charlie']), $yesterday);
        $loader->load('com', $this->namesFile([]), $today);
        $loader->load('net', $this->namesFile(['alpha', 'bravo', 'charlie']), $today);

        (new ZoneDiffer($this->clickhouse, self::DATABASE))->diff('com', $today, $yesterday);

        $this->buildCandidates();

        $candidates = (int) $this->clickhouse->scalar(
            'SELECT count() FROM '.self::DATABASE.'.com_candidates WHERE event_date = {d:Date}',
            ['d' => $today],
        );

        $this->assertSame(3, $candidates);

        $run = JobRun::where('step', 'candidates')->latest('id')->firstOrFail();

        $this->assertSame(JobRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(3, $run->rows);
        $this->assertSame(3, $run->bytes_done);
        $this->assertSame(3, $run->bytes_total);
        $this->assertStringContainsString('2 chunk(s)', (string) $run->message);
    }

    public function test_an_older_removal_is_never_searched_again(): void
    {
        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        $this->clickhouse->insertJsonEachRow(self::DATABASE.'.zone_events', [[
            'event_date' => $yesterday,
            'tld' => 'com',
            'name' => 'oldname',
            'event' => 'removed',
        ]]);

        $loader = new ZoneLoader($this->clickhouse, self::DATABASE);
        $loader->load('net', $this->namesFile(['oldname']), $today);

        $this->buildCandidates();

        $count = (int) $this->clickhouse->scalar(
            'SELECT count() FROM '.self::DATABASE.'.com_candidates WHERE name = {n:String}',
            ['n' => 'oldname'],
        );

        $this->assertSame(0, $count);
    }

    public function test_a_candidate_is_hidden_once_the_name_is_added_again(): void
    {
        $today = now()->format('Y-m-d');

        $this->clickhouse->insertJsonEachRow(self::DATABASE.'.com_candidates', [
            [
                'event_date' => $today,
                'name' => 'hiddenname',
                'weaker_found' => ['hiddenname.net'],
                'weaker_types' => ['other_tld'],
                'weaker_count' => 1,
                'tld_count' => 1,
                'exact_tld_count' => 1,
                'is_short' => 0,
                'score' => 20.0,
            ],
            [
                'event_date' => $today,
                'name' => 'visiblename',
                'weaker_found' => ['visiblename.net'],
                'weaker_types' => ['other_tld'],
                'weaker_count' => 1,
                'tld_count' => 1,
                'exact_tld_count' => 1,
                'is_short' => 0,
                'score' => 10.0,
            ],
        ]);

        $this->clickhouse->insertJsonEachRow(self::DATABASE.'.zone_events', [[
            'event_date' => $today,
            'tld' => 'com',
            'name' => 'hiddenname',
            'event' => 'added',
        ]]);

        $repository = new CandidateRepository($this->clickhouse, self::DATABASE);

        $rows = $repository->forDate($today);

        $this->assertCount(1, $rows);
        $this->assertSame('visiblename', $rows[0]['name']);
        $this->assertSame(1, $repository->countsForDate($today)['candidates']);
    }

    public function test_the_daily_list_shows_the_names_that_were_removed(): void
    {
        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        $loader = new ZoneLoader($this->clickhouse, self::DATABASE);

        $loader->load('com', $this->namesFile(['acmeplumbing']), $yesterday);
        $loader->load('com', $this->namesFile([]), $today);

        (new ZoneDiffer($this->clickhouse, self::DATABASE))->diff('com', $today, $yesterday);

        $repository = new CandidateRepository($this->clickhouse, self::DATABASE);

        $this->assertContains('acmeplumbing', $repository->removedNames($today));
        $this->assertSame(1, $repository->countsForDate($today)['removed']);
    }

    public function test_the_daily_candidate_page_can_be_searched_and_paginated(): void
    {
        $today = now()->format('Y-m-d');
        $candidates = [];

        for ($index = 0; $index < 30; $index++) {
            $name = sprintf('pagetest%02d', $index);

            $candidates[] = [
                'event_date' => $today,
                'name' => $name,
                'weaker_found' => [$name.'.net'],
                'weaker_types' => ['other_tld'],
                'weaker_count' => 1,
                'tld_count' => 1,
                'exact_tld_count' => 1,
                'is_short' => 0,
                'score' => 100 - $index,
            ];
        }

        $this->clickhouse->insertJsonEachRow(self::DATABASE.'.com_candidates', $candidates);

        $repository = new CandidateRepository($this->clickhouse, self::DATABASE);

        $first = $repository->pageForDate($today, '', 25, 1);

        $this->assertSame(30, $first['total']);
        $this->assertSame(2, $first['pages']);
        $this->assertCount(25, $first['candidates']);
        $this->assertSame('pagetest00', $first['candidates'][0]['name']);

        $second = $repository->pageForDate($today, '', 25, 2);

        $this->assertSame(2, $second['page']);
        $this->assertCount(5, $second['candidates']);
        $this->assertSame('pagetest25', $second['candidates'][0]['name']);

        $outOfRange = $repository->pageForDate($today, '', 25, 99);

        $this->assertSame(2, $outOfRange['page']);
        $this->assertCount(5, $outOfRange['candidates']);

        $domainSearch = $repository->pageForDate($today, 'pagetest29.com', 25, 1);

        $this->assertSame(1, $domainSearch['total']);
        $this->assertSame('pagetest29', $domainSearch['candidates'][0]['name']);

        $keywordSearch = $repository->pageForDate($today, 'page 29', 25, 1);

        $this->assertSame(1, $keywordSearch['total']);
        $this->assertSame('pagetest29', $keywordSearch['candidates'][0]['name']);
    }

    public function test_day_one_stores_the_baseline_without_a_diff_or_a_search(): void
    {
        $today = now()->format('Y-m-d');

        $loader = new ZoneLoader($this->clickhouse, self::DATABASE);

        $loader->load('com', $this->namesFile(['acmeplumbing']), $today);

        (new DiffZones((string) Str::uuid()))->handle(
            $this->clickhouse,
            new ZoneDiffer($this->clickhouse, self::DATABASE),
        );

        $events = (int) $this->clickhouse->scalar(
            'SELECT count() FROM '.self::DATABASE.'.zone_events',
        );

        $this->assertSame(0, $events);

        Queue::assertNotPushed(BuildComCandidates::class);
        Queue::assertPushed(Cleanup::class);

        $run = JobRun::where('step', 'diff')->latest('id')->firstOrFail();

        $this->assertSame(JobRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(0, $run->rows);
        $this->assertStringContainsString('Baseline only', (string) $run->message);
        $this->assertStringContainsString('no previous snapshot', (string) $run->message);
    }

    private function buildCandidates(): void
    {
        (new BuildComCandidates((string) Str::uuid()))->handle(
            $this->clickhouse,
            new WeakerSearcher($this->clickhouse, config('zones.weaker'), self::DATABASE),
            app(Scorer::class),
            app(OwnerIndependence::class),
        );
    }

    /**
     * @param  array<int, string>  $names
     */
    private function namesFile(array $names): string
    {
        $path = sys_get_temp_dir().'/dropwatch-pipeline-'.bin2hex(random_bytes(4)).'.tsv';

        $lines = array_map(fn (string $name) => $name."\t", $names);

        file_put_contents($path, $lines === [] ? '' : implode("\n", $lines)."\n");

        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    private function applySchema(): void
    {
        $path = database_path('clickhouse/schema.sql');

        // The test database is created once by an administrator; a limited
        // ClickHouse user must be able to run the suite without CREATE DATABASE.
        $sql = (string) file_get_contents($path);
        $sql = preg_replace('/^\s*CREATE DATABASE IF NOT EXISTS\s+zones\s*;\s*$/mi', '', $sql) ?? $sql;
        $sql = str_replace('zones.', self::DATABASE.'.', $sql);

        $file = tempnam(sys_get_temp_dir(), 'dropwatch-schema-').'.sql';

        file_put_contents($file, $sql);

        try {
            $this->clickhouse->executeFile($file);
        } finally {
            @unlink($file);
        }
    }

    private function truncate(): void
    {
        foreach (['zone_names', 'zone_events', 'com_candidates'] as $table) {
            $this->clickhouse->execute('TRUNCATE TABLE '.self::DATABASE.'.'.$table);
        }
    }
}
