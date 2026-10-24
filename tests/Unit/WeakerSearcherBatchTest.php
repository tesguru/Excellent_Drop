<?php

namespace Tests\Unit;

use App\Services\Candidates\WeakerSearcher;
use App\Services\ClickHouse\ClickHouseClient;
use Tests\TestCase;

class WeakerSearcherBatchTest extends TestCase
{
    public function test_a_chunk_runs_one_query_per_rule(): void
    {
        $client = new class extends ClickHouseClient
        {
            public array $queries = [];

            public array $responses = [];

            public function __construct() {}

            public function rowsFromSql(string $sql, array $settings = []): array
            {
                $this->queries[] = $sql;

                return array_shift($this->responses) ?? [];
            }
        };

        $client->responses = [
            [['base' => 'acmeplumbing', 'tld' => 'net', 'name' => 'acmeplumbing', 'ns' => 'ns1.example']],
            [['base' => 'acmeplumbing', 'tld' => 'net', 'name' => 'acme-plumbing', 'ns' => 'ns2.example']],
            [['base' => 'acmeplumbing', 'tld' => 'org', 'name' => 'getacmeplumbing', 'ns' => '']],
            [['base' => 'acmeplumbing', 'tld' => 'io', 'name' => 'acmeplumbinghub', 'ns' => '']],
            [['base' => 'acmeplumbing', 'tld' => 'co', 'name' => 'acmeplumbings', 'ns' => '']],
        ];

        $results = (new WeakerSearcher($client, ['max_per_rule' => 50], 'zones_test'))
            ->findMany(['acmeplumbing', 'othername'], '2026-10-24', ['com', 'net', 'org', 'io', 'co']);

        $this->assertCount(5, $client->queries);

        foreach ($client->queries as $query) {
            $this->assertStringContainsString('acmeplumbing', $query);
            $this->assertStringContainsString('othername', $query);
        }

        $this->assertContains('acmeplumbing.net', $results['acmeplumbing']['weaker_found']);
        $this->assertContains('acme-plumbing.net', $results['acmeplumbing']['weaker_found']);
        $this->assertContains('getacmeplumbing.org', $results['acmeplumbing']['weaker_found']);
        $this->assertContains('acmeplumbinghub.io', $results['acmeplumbing']['weaker_found']);
        $this->assertContains('acmeplumbings.co', $results['acmeplumbing']['weaker_found']);

        $signals = [];

        foreach ($results['acmeplumbing']['weaker_found'] as $index => $domain) {
            $signals[$domain] = $results['acmeplumbing']['weaker_types'][$index];
        }

        $this->assertStringContainsString('other_tld', $signals['acmeplumbing.net']);
        $this->assertStringContainsString('hyphen', $signals['acme-plumbing.net']);
        $this->assertStringContainsString('starts_with', $signals['getacmeplumbing.org']);
        $this->assertStringContainsString('ends_with', $signals['acmeplumbinghub.io']);
        $this->assertStringContainsString('plural', $signals['acmeplumbings.co']);

        $this->assertSame(['ns1.example'], $results['acmeplumbing']['nameservers']['acmeplumbing.net']);
        $this->assertSame([], $results['othername']['weaker_found']);
    }

    public function test_an_empty_chunk_does_not_query_clickhouse(): void
    {
        $client = new class extends ClickHouseClient
        {
            public int $queries = 0;

            public function __construct() {}

            public function rowsFromSql(string $sql, array $settings = []): array
            {
                $this->queries++;

                return [];
            }
        };

        $results = (new WeakerSearcher($client, ['max_per_rule' => 50], 'zones_test'))
            ->findMany([], '2026-10-24', ['com', 'net']);

        $this->assertSame([], $results);
        $this->assertSame(0, $client->queries);
    }
}
