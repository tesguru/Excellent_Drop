<?php

namespace Tests\Unit;

use App\Models\DatasetImport;
use App\Services\ClickHouse\ClickHouseClient;
use App\Services\PendingDelete\PendingDeleteClient;
use App\Services\PendingDelete\PendingDeleteException;
use App\Services\PendingDelete\PendingDeleteImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingDeleteImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_normalizes_rows_and_reports_the_import(): void
    {
        $clickhouse = $this->clickhouse();
        $client = $this->client([
            ['domain' => 'Acme.COM', 'status' => 'pending delete', 'drop_utc' => '2026-11-05 12:00:00', 'registrar' => 'NameCheap'],
            ['domain' => 'beta.net', 'status' => 'redemption', 'drop_utc' => '2026-11-05 12:00:00', 'registrar' => ''],
            ['domain' => 'bad_name.com', 'status' => 'dropped', 'drop_utc' => '', 'registrar' => ''],
            ['domain' => 'gamma.com', 'status' => 'dropped', 'drop_utc' => '2026-11-06 04:00:00', 'registrar' => 'Spaceship'],
        ]);

        $importer = new PendingDeleteImporter($clickhouse, $client);

        $dates = $importer->import($this->file(), $this->manifest());

        $this->assertSame(['2026-11-05', '2026-11-06'], $dates);

        $staging = $this->rowsFor($clickhouse, 'zones_test.pendingdelete_staging');
        $this->assertCount(2, $staging);
        $this->assertSame('acme', $staging[0]['name']);
        $this->assertSame('pending_delete', $staging[0]['status']);
        $this->assertSame(4, $staging[0]['label_length']);
        $this->assertSame('2026-11-05 12:00:00', $staging[0]['drop_utc']);
        $this->assertSame('gamma', $staging[1]['name']);
        $this->assertSame('dropped', $staging[1]['status']);

        $record = DatasetImport::query()->firstOrFail();
        $this->assertSame(DatasetImport::STATUS_SUCCESS, $record->status);
        $this->assertSame('pendingdelete.net', $record->source);
        $this->assertSame('pending', $record->dataset);
        $this->assertSame('com', $record->tld);
        $this->assertSame('2026-11-02 02:07:47', $record->built_utc);
        $this->assertSame(4, $record->downloaded);
        $this->assertSame(2, $record->invalid_rows);
        $this->assertSame(2, $record->new_domains);
        $this->assertSame(1, $record->updated_domains);
        $this->assertSame(1, $record->status_transitions);

        $this->assertTrue($this->hasCall($clickhouse, 'INSERT INTO zones_test.pendingdelete_status_history'));
        $this->assertTrue($this->hasCall($clickhouse, 'INSERT INTO zones_test.pendingdelete_names'));
    }

    public function test_a_successful_import_is_skipped_on_the_next_run(): void
    {
        DatasetImport::create([
            'source' => 'pendingdelete.net',
            'dataset' => 'pending',
            'tld' => 'com',
            'built_utc' => '2026-11-02 02:07:47',
            'format' => 'json',
            'status' => DatasetImport::STATUS_SUCCESS,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $clickhouse = $this->clickhouse();
        $client = $this->client([['domain' => 'acme.com', 'status' => 'dropped', 'drop_utc' => '2026-11-06 04:00:00']]);

        $importer = new PendingDeleteImporter($clickhouse, $client);

        $this->assertSame([], $importer->import($this->file(), $this->manifest()));
        $this->assertSame(0, $client->streams);
        $this->assertSame([], $clickhouse->inserts);
    }

    public function test_a_failed_download_leaves_the_current_state_untouched(): void
    {
        $clickhouse = $this->clickhouse();
        $client = $this->client([
            ['domain' => 'acme.com', 'status' => 'dropped', 'drop_utc' => '2026-11-06 04:00:00'],
        ]);
        $client->failAfterFirstRow = true;

        $importer = new PendingDeleteImporter($clickhouse, $client);

        try {
            $importer->import($this->file(), $this->manifest());
            $this->fail('The import should have thrown.');
        } catch (PendingDeleteException) {
            // expected
        }

        $record = DatasetImport::query()->firstOrFail();
        $this->assertSame(DatasetImport::STATUS_FAILED, $record->status);
        $this->assertNotNull($record->error);

        $this->assertFalse($this->hasCall($clickhouse, 'INSERT INTO zones_test.pendingdelete_names'));
        $this->assertTrue($this->hasCall($clickhouse, 'ALTER TABLE zones_test.pendingdelete_staging DELETE'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function client(array $rows): PendingDeleteClient
    {
        return new class($rows) extends PendingDeleteClient
        {
            public int $streams = 0;

            public bool $failAfterFirstRow = false;

            /**
             * @param  array<int, array<string, mixed>>  $rows
             */
            public function __construct(private readonly array $rows) {}

            public function datasetUrl(array $file): string
            {
                return 'https://example.test/'.$file['tld'].'.json';
            }

            public function stream(string $url, callable $onRow): array
            {
                $this->streams++;

                foreach ($this->rows as $row) {
                    $onRow($row);
                }

                if ($this->failAfterFirstRow) {
                    throw new PendingDeleteException('The download failed mid-file.');
                }

                return [
                    'rows' => count($this->rows),
                    'count' => count($this->rows),
                    'columns' => array_keys($this->rows[0] ?? []),
                ];
            }
        };
    }

    private function clickhouse(): ClickHouseClient
    {
        return new class extends ClickHouseClient
        {
            /** @var array<int, array{table:string, rows:array<int, array<string, mixed>>}> */
            public array $inserts = [];

            /** @var array<int, string> */
            public array $executes = [];

            public function __construct()
            {
                parent::__construct(['host' => '127.0.0.1', 'port' => 9000]);
            }

            public function database(): string
            {
                return 'zones_test';
            }

            public function rows(string $sql, array $params = []): array
            {
                if (str_contains($sql, 'countIf(c.name IS NULL)')) {
                    return [[
                        'merged' => 2,
                        'new_domains' => 2,
                        'updated_domains' => 1,
                        'status_transitions' => 1,
                    ]];
                }

                return [];
            }

            public function insertJsonEachRow(string $table, array $rows): void
            {
                $this->inserts[] = ['table' => $table, 'rows' => $rows];
            }

            public function execute(string $sql, array $params = []): void
            {
                $this->executes[] = $sql;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return [
            'built_utc' => '2026-11-02 02:07:47',
            'today' => '2026-11-01',
            'yesterday' => '2026-10-31',
            'lists' => ['pending'],
            'files' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function file(): array
    {
        return [
            'tld' => 'com',
            'list' => 'pending',
            'day' => '2026-11-01',
            'json' => 'https://example.test/com/pending.json',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsFor(ClickHouseClient $clickhouse, string $table): array
    {
        foreach ($clickhouse->inserts as $insert) {
            if ($insert['table'] === $table) {
                return $insert['rows'];
            }
        }

        return [];
    }

    private function hasCall(ClickHouseClient $clickhouse, string $needle): bool
    {
        foreach ($clickhouse->executes as $sql) {
            if (str_contains($sql, $needle)) {
                return true;
            }
        }

        return false;
    }
}
