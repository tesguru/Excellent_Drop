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

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $database) !== 1) {
            $this->error("Invalid ClickHouse database name: {$database}");

            return self::FAILURE;
        }

        // The schema file is written for the "zones" database, the configured
        // one wins so a test database can be used. The CREATE DATABASE line is
        // handled separately: a limited user may use an existing database but
        // cannot create one.
        $sql = (string) file_get_contents($path);
        $sql = preg_replace('/^\s*CREATE DATABASE IF NOT EXISTS\s+zones\s*;\s*$/mi', '', $sql) ?? $sql;
        $sql = str_replace('zones.', $database.'.', $sql);

        if (! $this->databaseExists($clickhouse, $database)) {
            $clickhouse->execute('CREATE DATABASE IF NOT EXISTS `'.str_replace('`', '``', $database).'`');
        }

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

    private function databaseExists(ClickHouseClient $clickhouse, string $database): bool
    {
        // The HTTP client sends the configured database with every request, so
        // it cannot run this check before that database exists. The binary does
        // not select a database and answers against the default one.
        $count = trim($clickhouse->run(
            'SELECT count() FROM system.databases WHERE name = {n:String}',
            ['n' => $database],
        ));

        return $count !== '' && $count !== '0';
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
