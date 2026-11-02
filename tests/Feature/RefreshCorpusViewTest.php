<?php

namespace Tests\Feature;

use App\Services\ClickHouse\ClickHouseClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshCorpusViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_pins_every_tld_to_its_own_newest_snapshot(): void
    {
        $clickhouse = $this->clickhouse([
            ['tld' => 'com', 'latest' => '2026-10-25'],
            ['tld' => 'net', 'latest' => '2026-10-25'],
            ['tld' => 'app', 'latest' => '2026-11-01'],
        ]);

        $this->artisan('zones:refresh-corpus')->assertOk();

        $ddl = $clickhouse->executes[0];

        // TLDs that share a date become one clause, so ClickHouse can still
        // prune partitions and use the (tld, name) primary key. The elements
        // are single-quoted: double quotes make ClickHouse read them as
        // identifiers, not strings.
        $this->assertStringContainsString("(tld IN ['com', 'net'] AND snapshot_date = '2026-10-25')", $ddl);
        $this->assertStringContainsString("(tld IN ['app'] AND snapshot_date = '2026-11-01')", $ddl);
        $this->assertStringContainsString('CREATE OR REPLACE VIEW `zones_test`.weaker_corpus', $ddl);

        // The pending-delete branch stays: those names are registrations that
        // no zone snapshot may contain.
        $this->assertStringContainsString('pendingdelete_names FINAL WHERE status != \'dropped\'', $ddl);
    }

    public function test_the_zone_branch_matches_nothing_before_the_first_snapshot(): void
    {
        $clickhouse = $this->clickhouse([]);

        $this->artisan('zones:refresh-corpus')->assertOk();

        $this->assertStringContainsString('FROM `zones_test`.zone_names WHERE (0)', $clickhouse->executes[0]);
    }

    public function test_a_quoted_tld_cannot_break_out_of_the_array_literal(): void
    {
        $clickhouse = $this->clickhouse([['tld' => "a'b", 'latest' => '2026-11-01']]);

        $this->artisan('zones:refresh-corpus')->assertOk();

        $this->assertStringContainsString("(tld IN ['a\\'b']", $clickhouse->executes[0]);
    }

    /**
     * @param  array<int, array<string, string>>  $snapshots
     */
    private function clickhouse(array $snapshots): ClickHouseClient
    {
        config(['zones.clickhouse.database' => 'zones_test']);

        return tap(new class($snapshots) extends ClickHouseClient
        {
            /** @var array<int, string> */
            public array $executes = [];

            /**
             * @param  array<int, array<string, string>>  $snapshots
             */
            public function __construct(private readonly array $snapshots)
            {
                parent::__construct(['host' => '127.0.0.1', 'port' => 9000]);
            }

            public function database(): string
            {
                return 'zones_test';
            }

            public function rows(string $sql, array $params = []): array
            {
                return $this->snapshots;
            }

            public function execute(string $sql, array $params = []): void
            {
                $this->executes[] = $sql;
            }
        }, fn (ClickHouseClient $fake) => $this->app->instance(ClickHouseClient::class, $fake));
    }
}
