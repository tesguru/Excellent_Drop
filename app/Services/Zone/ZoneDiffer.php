<?php

namespace App\Services\Zone;

use App\Services\ClickHouse\ClickHouseClient;

class ZoneDiffer
{
    public function __construct(
        private readonly ClickHouseClient $clickhouse,
        private readonly string $database = 'zones',
    ) {}

    public function hasSnapshot(string $tld, string $date): bool
    {
        $count = (int) $this->clickhouse->scalar(
            sprintf(
                'SELECT count() FROM %s.zone_names WHERE snapshot_date = {d:Date} AND tld = {tld:String}',
                $this->database,
            ),
            ['d' => $date, 'tld' => $tld],
        );

        return $count > 0;
    }

    /**
     * Compare two daily snapshots for one TLD and record the differences.
     *
     * Names that vanished are recorded as `removed` and names that appeared
     * as `added`. A removal never means the name is available for
     * registration, it only means it left the zone file.
     *
     * @return array{removed:int, added:int}
     */
    public function diff(string $tld, string $today, string $yesterday): array
    {
        $this->clearExistingEvents($tld, $today);

        $this->insertEventSet($tld, $today, $yesterday, 'removed');
        $this->insertEventSet($tld, $today, $yesterday, 'added');

        return [
            'removed' => $this->eventCount($tld, $today, 'removed'),
            'added' => $this->eventCount($tld, $today, 'added'),
        ];
    }

    public function eventCount(string $tld, string $date, string $event): int
    {
        return (int) $this->clickhouse->scalar(
            sprintf(
                'SELECT count() FROM %s.zone_events WHERE event_date = {d:Date} AND tld = {tld:String} AND event = {event:String}',
                $this->database,
            ),
            ['d' => $date, 'tld' => $tld, 'event' => $event],
        );
    }

    /**
     * Make the diff idempotent: events recorded earlier on the same day for
     * the same zone are removed first.
     */
    private function clearExistingEvents(string $tld, string $date): void
    {
        $this->clickhouse->execute(
            sprintf(
                'ALTER TABLE %s.zone_events DELETE WHERE event_date = {d:Date} AND tld = {tld:String} '
                .'SETTINGS mutations_sync = 2',
                $this->database,
            ),
            ['d' => $date, 'tld' => $tld],
        );
    }

    private function insertEventSet(string $tld, string $today, string $yesterday, string $event): void
    {
        [$left, $right] = $event === 'removed'
            ? [$yesterday, $today]
            : [$today, $yesterday];

        // EXCEPT (and ANTI JOIN) materialise the whole right-hand set in RAM.
        // The .com zone has ~176M names per snapshot, which needs several GiB
        // and kills this 2.9 GB server. A partial_merge LEFT JOIN sorts both
        // sides on disk instead, so memory stays flat at the cost of ~5
        // minutes per direction for .com.
        $sql = sprintf(
            'INSERT INTO %s.zone_events (event_date, tld, name, event) '
            .'SELECT {today:Date}, {tld:String}, a.name, {event:String} FROM ('
            .'SELECT name FROM %s.zone_names WHERE snapshot_date = {left:Date} AND tld = {tld:String}'
            .') AS a '
            .'LEFT JOIN ('
            .'SELECT name FROM %s.zone_names WHERE snapshot_date = {right:Date} AND tld = {tld:String}'
            .') AS b ON a.name = b.name '
            .'WHERE isNull(b.name) '
            .'SETTINGS join_algorithm = \'partial_merge\', join_use_nulls = 1, '
            .'max_bytes_before_external_join = 268435456, max_memory_usage = 1500000000, max_threads = 2',
            $this->database,
            $this->database,
            $this->database,
        );

        $this->clickhouse->execute($sql, [
            'today' => $today,
            'tld' => $tld,
            'event' => $event,
            'left' => $left,
            'right' => $right,
        ]);
    }
}
