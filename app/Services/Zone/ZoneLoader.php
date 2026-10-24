<?php

namespace App\Services\Zone;

use App\Services\ClickHouse\ClickHouseClient;

class ZoneLoader
{
    public function __construct(
        private readonly ClickHouseClient $clickhouse,
        private readonly string $database = 'zones',
    ) {}

    /**
     * Load a names file (`name<TAB>nameservers`) into the snapshot table.
     *
     * The file is streamed into clickhouse-client, so a multi gigabyte list
     * of names never passes through PHP.
     *
     * @return int number of rows loaded
     */
    public function load(string $tld, string $namesPath, string $snapshotDate): int
    {
        $this->clearExistingSnapshot($tld, $snapshotDate);

        // An empty zone file has nothing to insert and ClickHouse refuses
        // the statement, so the snapshot is simply empty.
        if (filesize($namesPath) === 0) {
            return 0;
        }

        $sql = sprintf(
            'INSERT INTO %s.zone_names (snapshot_date, tld, name, name_rev, ns) '
            .'SELECT {d:Date}, {tld:String}, name, reverse(name), ns '
            ."FROM input('name String, ns String') FORMAT TSV",
            $this->database,
        );

        $this->clickhouse->insertTsvFromFile($sql, $namesPath, [
            'd' => $snapshotDate,
            'tld' => $tld,
        ]);

        return (int) $this->clickhouse->scalar(
            sprintf(
                'SELECT count() FROM %s.zone_names WHERE snapshot_date = {d:Date} AND tld = {tld:String}',
                $this->database,
            ),
            ['d' => $snapshotDate, 'tld' => $tld],
        );
    }

    /**
     * Remove a previous load for the same day and TLD so re-running a step
     * does not duplicate rows.
     */
    public function clearExistingSnapshot(string $tld, string $snapshotDate): void
    {
        $this->clickhouse->execute(
            sprintf(
                'ALTER TABLE %s.zone_names DELETE WHERE snapshot_date = {d:Date} AND tld = {tld:String} '
                .'SETTINGS mutations_sync = 2',
                $this->database,
            ),
            ['d' => $snapshotDate, 'tld' => $tld],
        );
    }

    public function rowCount(string $tld, string $snapshotDate): int
    {
        return (int) $this->clickhouse->scalar(
            sprintf(
                'SELECT count() FROM %s.zone_names WHERE snapshot_date = {d:Date} AND tld = {tld:String}',
                $this->database,
            ),
            ['d' => $snapshotDate, 'tld' => $tld],
        );
    }
}
