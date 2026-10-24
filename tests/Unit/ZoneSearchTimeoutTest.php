<?php

namespace Tests\Unit;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Zone\ZoneSearch;
use Tests\TestCase;

class ZoneSearchTimeoutTest extends TestCase
{
    public function test_a_clickhouse_timeout_becomes_a_too_broad_message(): void
    {
        $client = new class extends ClickHouseClient
        {
            public function __construct() {}

            public function row(string $sql, array $params = []): ?array
            {
                return ['total' => 1, 'latest' => '2026-10-24'];
            }

            public function rows(string $sql, array $params = []): array
            {
                throw new ClickHouseException(
                    'Code: 159. DB::Exception: Timeout exceeded: elapsed 20 seconds, maximum: 20.',
                );
            }
        };

        $result = (new ZoneSearch($client, 'zones_test'))->search([
            'query' => 'acmeplumbing',
            'mode' => 'contains',
            'tlds' => [],
            'min_length' => null,
            'max_length' => null,
            'hide_digits' => false,
            'hide_hyphens' => false,
            'page' => 1,
        ]);

        $this->assertSame([], $result['results']);
        $this->assertStringContainsString('too broad', (string) $result['error']);
    }
}
