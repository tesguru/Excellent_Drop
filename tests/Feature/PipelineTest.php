<?php

namespace Tests\Feature;

use App\Jobs\BuildComCandidates;
use App\Services\CandidateRepository;
use App\Services\Candidates\OwnerIndependence;
use App\Services\Candidates\Scorer;
use App\Services\Candidates\WeakerSearcher;
use App\Services\ClickHouse\ClickHouseClient;
use App\Services\Weaker\WeakerSiteChecker;
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

        // The RDAP step must not reach the network from a test.
        Queue::fake();

        // No network calls in the test.
        $this->app->instance(WeakerSiteChecker::class, new class extends WeakerSiteChecker
        {
            public function check(string $domain): array
            {
                return [
                    'http_status' => 200,
                    'has_contact_page' => false,
                    'title' => null,
                    'nameservers' => [],
                    'error' => null,
                ];
            }

            public function nameservers(string $domain): array
            {
                return [];
            }
        });
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
            'SELECT name, weaker_found, weaker_types, weaker_count, tld_count, is_short, score FROM '.self::DATABASE.'.com_candidates ORDER BY name',
        );

        $this->assertCount(1, $candidates);
        $this->assertSame('acmeplumbing', $candidates[0]['name']);
        $this->assertSame(0, (int) $candidates[0]['is_short']);
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

    private function buildCandidates(): void
    {
        (new BuildComCandidates((string) Str::uuid()))->handle(
            $this->clickhouse,
            new WeakerSearcher($this->clickhouse, config('zones.weaker'), self::DATABASE),
            app(Scorer::class),
            app(OwnerIndependence::class),
            app(WeakerSiteChecker::class),
        );
    }

    /**
     * @param  array<int, string>  $names
     */
    private function namesFile(array $names): string
    {
        $path = sys_get_temp_dir().'/dropwatch-pipeline-'.bin2hex(random_bytes(4)).'.tsv';

        $lines = $names;

        file_put_contents($path, $lines === [] ? '' : implode("\n", $lines)."\n");

        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    private function applySchema(): void
    {
        $path = database_path('clickhouse/schema.sql');

        $sql = str_replace(
            ['zones.', 'CREATE DATABASE IF NOT EXISTS zones'],
            [self::DATABASE.'.', 'CREATE DATABASE IF NOT EXISTS '.self::DATABASE],
            (string) file_get_contents($path),
        );

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
