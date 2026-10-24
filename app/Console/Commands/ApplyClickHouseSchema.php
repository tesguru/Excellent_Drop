<?php

namespace App\Console\Commands;

use App\Services\ClickHouse\ClickHouseClient;
use Illuminate\Console\Command;

class ApplyClickHouseSchema extends Command
{
    protected $signature = 'zones:ch-migrate';

    protected $description = 'Create or update the ClickHouse tables used by DropWatch';

    public function handle(ClickHouseClient $clickhouse): int
    {
        $path = database_path('clickhouse/schema.sql');

        if (! is_readable($path)) {
            $this->error("Schema file not found: {$path}");

            return self::FAILURE;
        }

        $database = (string) config('zones.clickhouse.database', 'zones');

        // The schema file is written for the "zones" database, the configured
        // one wins so a test database can be used.
        $sql = str_replace(['zones.', 'CREATE DATABASE IF NOT EXISTS zones'], [$database.'.', 'CREATE DATABASE IF NOT EXISTS '.$database], (string) file_get_contents($path));

        $file = tempnam(sys_get_temp_dir(), 'dropwatch-schema-').'.sql';

        file_put_contents($file, $sql);

        try {
            $clickhouse->executeFile($file);
        } finally {
            @unlink($file);
        }

        $this->materializeProjection($clickhouse, $database);

        $this->info('ClickHouse schema applied to '.$database.'.');

        return self::SUCCESS;
    }

    /**
     * Parts written before the projection existed do not contain it yet.
     * Materializing is a full rewrite, so only run it when it is needed.
     */
    private function materializeProjection(ClickHouseClient $clickhouse, string $database): void
    {
        $parts = (int) $clickhouse->scalar(sprintf(
            "SELECT count() FROM system.parts WHERE database = %s AND table = 'zone_names' AND active",
            $this->sqlString($database),
        ));

        if ($parts === 0) {
            return;
        }

        $covered = (int) $clickhouse->scalar(sprintf(
            "SELECT count() FROM system.projection_parts WHERE database = %s AND table = 'zone_names' "
            ."AND name = 'name_rev_order' AND active",
            $this->sqlString($database),
        ));

        if ($covered >= $parts) {
            return;
        }

        $clickhouse->execute(sprintf(
            'ALTER TABLE %s.zone_names MATERIALIZE PROJECTION name_rev_order',
            $database,
        ));

        $this->info('Materialized the name_rev_order projection.');
    }

    private function sqlString(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
