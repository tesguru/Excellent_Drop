<?php

namespace Tests\Unit;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use Tests\TestCase;

class ClickHouseClientRedactionTest extends TestCase
{
    private string $dir;

    private string $binary;

    private string $originalPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/dropwatch-clickhouse-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);

        $this->binary = $this->dir.'/dropwatch-fake-clickhouse';
        file_put_contents($this->binary, "#!/bin/sh\necho \"failed: \$*\" >&2\nexit 1\n");
        chmod($this->binary, 0755);

        $this->originalPath = (string) getenv('PATH');
        putenv('PATH='.$this->dir.PATH_SEPARATOR.$this->originalPath);
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
        @unlink($this->binary);
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_a_failed_batch_query_redacts_the_password(): void
    {
        $this->assertRedacted(fn () => $this->client()->rowsFromSql('SELECT 1'));
    }

    public function test_a_failed_stream_redacts_the_password(): void
    {
        $this->assertRedacted(fn () => $this->client()->streamRows('SELECT 1', fn () => null));
    }

    public function test_a_timed_out_query_redacts_the_password(): void
    {
        file_put_contents($this->binary, "#!/bin/sh\nsleep 2\nexit 0\n");

        $this->assertRedacted(fn () => $this->client(0.2)->rowsFromSql('SELECT 1'));
    }

    public function test_a_slow_consumer_does_not_time_out_the_stream(): void
    {
        file_put_contents($this->binary, "#!/bin/sh\necho '{\"ok\":1}'\n");

        $rows = [];

        $this->client(0.2)->streamRows('SELECT 1', function (array $row) use (&$rows): void {
            usleep(400000);
            $rows[] = $row;
        });

        $this->assertSame([['ok' => 1]], $rows);
    }

    private function assertRedacted(callable $call): void
    {
        try {
            $call();
            $this->fail('The client should have thrown.');
        } catch (ClickHouseException $exception) {
            $this->assertStringNotContainsString('unit-secret-password', $exception->getMessage());
            $this->assertStringContainsString('REDACTED', $exception->getMessage());
        }
    }

    private function client(float $timeout = 5.0): ClickHouseClient
    {
        return new ClickHouseClient([
            'host' => '127.0.0.1',
            'port' => 9000,
            'http_port' => 0,
            'database' => 'zones_test',
            'client_bin' => 'dropwatch-fake-clickhouse',
            'user' => 'dropwatch',
            'password' => 'unit-secret-password',
            'timeout' => $timeout,
        ]);
    }
}
