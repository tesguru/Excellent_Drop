<?php

namespace Tests\Unit;

use App\Services\Candidates\WeakerSearcher;
use App\Services\ClickHouse\ClickHouseClient;
use Tests\TestCase;

class WeakerSearcherBatchTest extends TestCase
{
    public function test_a_chunk_runs_one_query_per_active_rule(): void
    {
        $client = new class extends ClickHouseClient
        {
            public array $queries = [];

            public array $responses = [];

            public int $projectionParts = 1;

            public function __construct() {}

            public function scalar(string $sql, array $params = []): mixed
            {
                return $this->projectionParts;
            }

            public function rowsFromSql(string $sql, array $settings = []): array
            {
                $this->queries[] = $sql;

                return array_shift($this->responses) ?? [];
            }
        };

        $client->responses = [
            [['base' => 'acmeplumbing', 'tld' => 'net', 'name' => 'acmeplumbing', 'ns' => 'ns1.example']],
            [['name' => 'acme-plumbing', 'tld' => 'net', 'ns' => 'ns2.example']],
            [['name' => 'acmeplumbinghub', 'tld' => 'io', 'ns' => '']],
            [['name' => 'getacmeplumbing', 'tld' => 'org', 'ns' => '']],
            [['name' => 'acmeplumbings', 'tld' => 'co', 'ns' => '']],
            [['base' => 'acmeplumbing', 'exact_tld_count' => 4, 'known_ns' => 2, 'ns_sets' => 2]],
        ];

        $results = (new WeakerSearcher($client, ['max_per_rule' => 50], 'zones_test'))
            ->findMany(['acmeplumbing', 'othername'], '2026-10-24', ['com', 'net', 'org', 'io', 'co']);

        // One query per active rule plus one exact-TLD count query for the chunk.
        $this->assertCount(6, $client->queries);
        $this->assertStringContainsString('othername', $client->queries[0]);
        $this->assertStringContainsString('acmeplumbing', $client->queries[0]);
        $this->assertStringContainsString('acme-plumbing', $client->queries[1]);
        $this->assertStringContainsString('othername', $client->queries[2]);
        $this->assertStringContainsString('name_rev', $client->queries[3]);
        $this->assertStringContainsString('acmeplumbings', $client->queries[4]);
        $this->assertStringContainsString('othername', $client->queries[5]);

        $this->assertContains('acmeplumbing.net', $results['acmeplumbing']['weaker_found']);
        $this->assertContains('acme-plumbing.net', $results['acmeplumbing']['weaker_found']);
        $this->assertContains('acmeplumbinghub.io', $results['acmeplumbing']['weaker_found']);
        $this->assertContains('getacmeplumbing.org', $results['acmeplumbing']['weaker_found']);
        $this->assertContains('acmeplumbings.co', $results['acmeplumbing']['weaker_found']);

        $signals = [];

        foreach ($results['acmeplumbing']['weaker_found'] as $index => $domain) {
            $signals[$domain] = $results['acmeplumbing']['weaker_types'][$index];
        }

        $this->assertStringContainsString('other_tld', $signals['acmeplumbing.net']);
        $this->assertStringContainsString('hyphen', $signals['acme-plumbing.net']);
        $this->assertStringContainsString('starts_with', $signals['acmeplumbinghub.io']);
        $this->assertStringContainsString('ends_with', $signals['getacmeplumbing.org']);
        $this->assertStringContainsString('plural', $signals['acmeplumbings.co']);

        $this->assertSame(['ns1.example'], $results['acmeplumbing']['nameservers']['acmeplumbing.net']);
        $this->assertSame(4, $results['acmeplumbing']['exact_tld_count']);
        $this->assertFalse($results['acmeplumbing']['same_nameservers']);
        $this->assertSame(0, $results['othername']['exact_tld_count']);
        $this->assertSame([], $results['othername']['weaker_found']);
    }

    public function test_ends_with_is_skipped_when_the_projection_is_missing(): void
    {
        $client = new class extends ClickHouseClient
        {
            public array $queries = [];

            public function __construct() {}

            public function scalar(string $sql, array $params = []): mixed
            {
                return 0;
            }

            public function rowsFromSql(string $sql, array $settings = []): array
            {
                $this->queries[] = $sql;

                return [];
            }
        };

        (new WeakerSearcher($client, ['max_per_rule' => 50], 'zones_test'))
            ->findMany(['acmeplumbing'], '2026-10-24', ['com', 'net']);

        $this->assertCount(5, $client->queries);
        $this->assertStringNotContainsString('name_rev', implode("\n", $client->queries));
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
