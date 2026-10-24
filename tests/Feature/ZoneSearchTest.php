<?php

namespace Tests\Feature;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\Zone\ZoneLoader;
use App\Services\Zone\ZoneSearch;
use Tests\TestCase;

/**
 * Search behaviour against a real ClickHouse server.
 *
 * It runs in its own database (zones_test) so the production data is never
 * touched. The test is skipped when ClickHouse is not reachable.
 */
class ZoneSearchTest extends TestCase
{
    private const DATABASE = 'zones_test';

    private ClickHouseClient $clickhouse;

    private ZoneSearch $search;

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

        $this->search = new ZoneSearch($this->clickhouse, self::DATABASE);
    }

    public function test_the_modes_query_only_the_latest_snapshot(): void
    {
        $today = now()->format('Y-m-d');

        $this->loadZones([
            'com' => ['acmeplumbing', 'acmecorp', 'getacmeplumbing', 'acme123', 'oak', 'ab-c'],
            'net' => ['acmeplumbing', 'getacmeplumbing'],
            'org' => ['acmeplumbing'],
        ], $today);

        $this->loadZones(['com' => ['oldonly']], now()->subDay()->format('Y-m-d'));

        $contains = $this->search->search($this->filters(['query' => 'acme']));

        $this->assertSame([
            'acme123.com',
            'acmecorp.com',
            'acmeplumbing.com',
            'acmeplumbing.net',
            'acmeplumbing.org',
            'getacmeplumbing.com',
            'getacmeplumbing.net',
        ], $this->domains($contains));

        $this->assertNotContains('oldonly.com', $this->domains($contains));

        $starts = $this->search->search($this->filters(['query' => 'acme', 'mode' => 'starts']));

        $this->assertSame([
            'acme123.com',
            'acmecorp.com',
            'acmeplumbing.com',
            'acmeplumbing.net',
            'acmeplumbing.org',
        ], $this->domains($starts));

        $ends = $this->search->search($this->filters(['query' => 'plumbing', 'mode' => 'ends']));

        $this->assertCount(5, $ends['results']);
        $this->assertContains('acmeplumbing.com', $this->domains($ends));
        $this->assertContains('getacmeplumbing.net', $this->domains($ends));

        $exact = $this->search->search($this->filters(['query' => 'acmeplumbing', 'mode' => 'exact']));

        $this->assertSame([
            'acmeplumbing.com',
            'acmeplumbing.net',
            'acmeplumbing.org',
        ], $this->domains($exact));
    }

    public function test_the_tld_and_length_filters_narrow_the_results(): void
    {
        $today = now()->format('Y-m-d');

        $this->loadZones([
            'com' => ['acmeplumbing', 'acmecorp', 'getacmeplumbing', 'acme123', 'ab-c'],
            'net' => ['acmeplumbing', 'getacmeplumbing'],
            'org' => ['acmeplumbing'],
        ], $today);

        $netOnly = $this->search->search($this->filters(['query' => 'acme', 'tlds' => ['net']]));

        $this->assertSame(['acmeplumbing.net', 'getacmeplumbing.net'], $this->domains($netOnly));

        $short = $this->search->search($this->filters(['query' => 'acme', 'max_length' => 8]));

        $this->assertSame(['acme123.com', 'acmecorp.com'], $this->domains($short));

        $long = $this->search->search($this->filters(['query' => 'acme', 'min_length' => 12]));

        $this->assertSame([
            'acmeplumbing.com',
            'acmeplumbing.net',
            'acmeplumbing.org',
            'getacmeplumbing.com',
            'getacmeplumbing.net',
        ], $this->domains($long));

        $withDigits = $this->search->search($this->filters(['query' => 'acme']));
        $withoutDigits = $this->search->search($this->filters(['query' => 'acme', 'hide_digits' => true]));

        $this->assertContains('acme123.com', $this->domains($withDigits));
        $this->assertNotContains('acme123.com', $this->domains($withoutDigits));

        $withHyphens = $this->search->search($this->filters(['query' => 'ab', 'mode' => 'starts']));
        $withoutHyphens = $this->search->search($this->filters([
            'query' => 'ab',
            'mode' => 'starts',
            'hide_hyphens' => true,
        ]));

        $this->assertSame(['ab-c.com'], $this->domains($withHyphens));
        $this->assertSame([], $this->domains($withoutHyphens));
    }

    public function test_percent_and_underscore_are_literal_characters(): void
    {
        $this->loadZones([
            'com' => ['per%cent', 'perxcent', 'per_cent', 'perxcenty'],
        ], now()->format('Y-m-d'));

        $percent = $this->search->search($this->filters(['query' => 'per%cent']));
        $underscore = $this->search->search($this->filters(['query' => 'per_cent']));

        $this->assertSame(['per%cent.com'], $this->domains($percent));
        $this->assertSame(['per_cent.com'], $this->domains($underscore));

        $tooShort = $this->search->search($this->filters(['query' => 'pe']));

        $this->assertSame([], $tooShort['results']);
        $this->assertStringContainsString('at least 3 characters', (string) $tooShort['error']);

        $inverted = $this->search->search($this->filters([
            'query' => 'per',
            'min_length' => 10,
            'max_length' => 5,
        ]));

        $this->assertStringContainsString('cannot be greater', (string) $inverted['error']);
    }

    public function test_each_result_reports_removed_and_added_events_from_the_last_45_days(): void
    {
        $today = now()->format('Y-m-d');

        $this->loadZones([
            'com' => ['acmeplumbing', 'acmecorp'],
            'net' => ['acmeplumbing'],
            'org' => ['acmeplumbing'],
        ], $today);

        $this->clickhouse->insertJsonEachRow(self::DATABASE.'.zone_events', [
            [
                'event_date' => now()->subDays(10)->format('Y-m-d'),
                'tld' => 'com',
                'name' => 'acmeplumbing',
                'event' => 'removed',
            ],
            [
                'event_date' => now()->subDays(5)->format('Y-m-d'),
                'tld' => 'net',
                'name' => 'acmeplumbing',
                'event' => 'added',
            ],
            [
                'event_date' => now()->subDays(46)->format('Y-m-d'),
                'tld' => 'com',
                'name' => 'acmecorp',
                'event' => 'removed',
            ],
        ]);

        $results = [];

        foreach ($this->search->search($this->filters([
            'query' => 'acme',
            'tlds' => ['com', 'net', 'org'],
        ]))['results'] as $result) {
            $results[$result['domain']] = $result;
        }

        $this->assertSame(now()->subDays(10)->format('Y-m-d'), $results['acmeplumbing.com']['removed_on']);
        $this->assertNull($results['acmeplumbing.com']['added_on']);

        $this->assertNull($results['acmeplumbing.net']['removed_on']);
        $this->assertSame(now()->subDays(5)->format('Y-m-d'), $results['acmeplumbing.net']['added_on']);

        $this->assertNull($results['acmeplumbing.org']['removed_on']);
        $this->assertNull($results['acmeplumbing.org']['added_on']);

        $this->assertNull($results['acmecorp.com']['removed_on']);
    }

    public function test_results_are_paginated_at_100_with_a_hard_cap_of_5000(): void
    {
        $names = [];

        for ($index = 1; $index <= 105; $index++) {
            $names[] = 'page'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);
        }

        $this->loadZones(['com' => $names], now()->format('Y-m-d'));

        $first = $this->search->search($this->filters(['query' => 'page', 'mode' => 'starts']));

        $this->assertCount(ZoneSearch::PER_PAGE, $first['results']);
        $this->assertSame(1, $first['page']);
        $this->assertTrue($first['has_more']);

        $second = $this->search->search($this->filters(['query' => 'page', 'mode' => 'starts', 'page' => 2]));

        $this->assertCount(5, $second['results']);
        $this->assertSame(2, $second['page']);
        $this->assertFalse($second['has_more']);

        $capped = $this->search->search($this->filters(['query' => 'page', 'mode' => 'starts', 'page' => 999]));

        $this->assertSame(ZoneSearch::MAX_PAGES, $capped['page']);
        $this->assertSame(50, ZoneSearch::MAX_PAGES);
        $this->assertSame(5000, ZoneSearch::MAX_RESULTS);
        $this->assertSame([], $capped['results']);
        $this->assertFalse($capped['has_more']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function filters(array $overrides = []): array
    {
        return array_merge([
            'query' => '',
            'mode' => 'contains',
            'tlds' => [],
            'min_length' => null,
            'max_length' => null,
            'hide_digits' => false,
            'hide_hyphens' => false,
            'page' => 1,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<int, string>
     */
    private function domains(array $result): array
    {
        return array_map(fn (array $row) => $row['domain'], $result['results']);
    }

    /**
     * @param  array<string, array<int, string>>  $zones
     */
    private function loadZones(array $zones, string $date): void
    {
        $loader = new ZoneLoader($this->clickhouse, self::DATABASE);

        foreach ($zones as $tld => $names) {
            $loader->load($tld, $this->namesFile($names), $date);
        }
    }

    /**
     * @param  array<int, string>  $names
     */
    private function namesFile(array $names): string
    {
        $path = sys_get_temp_dir().'/dropwatch-search-'.bin2hex(random_bytes(4)).'.tsv';

        $lines = array_map(fn (string $name) => $name."\t", $names);

        file_put_contents($path, implode("\n", $lines)."\n");

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
