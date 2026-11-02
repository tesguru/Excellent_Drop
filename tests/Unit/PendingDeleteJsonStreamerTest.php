<?php

namespace Tests\Unit;

use App\Services\PendingDelete\JsonRowStreamer;
use App\Services\PendingDelete\PendingDeleteException;
use Tests\TestCase;

class PendingDeleteJsonStreamerTest extends TestCase
{
    public function test_rows_survive_being_fed_one_byte_at_a_time(): void
    {
        $json = '{"dataset":"pending","columns":["domain","status","registrar"],'
            .'"rows":[["a.com","dropped","Acme, Inc."],["b.net","redemption","Quote \"x\""]],"count":2}';

        $streamer = new JsonRowStreamer;
        $rows = [];

        foreach (str_split($json) as $byte) {
            $rows = array_merge($rows, $streamer->feed($byte));
        }

        $result = $streamer->finish();

        $this->assertSame([
            ['a.com', 'dropped', 'Acme, Inc.'],
            ['b.net', 'redemption', 'Quote "x"'],
        ], $rows);
        $this->assertSame(2, $result['rows']);
        $this->assertSame(2, $result['count']);
        $this->assertSame(['domain', 'status', 'registrar'], $result['columns']);
    }

    public function test_an_empty_rows_array_finishes_cleanly(): void
    {
        $streamer = new JsonRowStreamer;
        $streamer->feed('{"columns":["domain"],"rows":[],"count":0}');

        $this->assertSame(['rows' => 0, 'count' => 0, 'columns' => ['domain']], $streamer->finish());
    }

    public function test_a_partial_download_is_rejected(): void
    {
        $streamer = new JsonRowStreamer;
        $streamer->feed('{"columns":["domain"],"rows":[["a.com"],["b.com"');

        $this->expectException(PendingDeleteException::class);
        $this->expectExceptionMessage('partial download');

        $streamer->finish();
    }

    public function test_a_declared_count_that_does_not_match_is_rejected(): void
    {
        $streamer = new JsonRowStreamer;
        $streamer->feed('{"columns":["domain"],"rows":[["a.com"],["b.com"]],"count":3}');

        $this->expectException(PendingDeleteException::class);
        $this->expectExceptionMessage('declared 3 rows but 2 were read');

        $streamer->finish();
    }

    public function test_metadata_without_a_rows_array_is_rejected(): void
    {
        $streamer = new JsonRowStreamer;
        $streamer->feed('{"columns":["domain"],"count":0}');

        $this->expectException(PendingDeleteException::class);
        $this->expectExceptionMessage('does not contain a rows array');

        $streamer->finish();
    }
}
