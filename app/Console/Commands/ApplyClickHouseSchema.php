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

        $this->info('ClickHouse schema applied to '.$database.'.');

        return self::SUCCESS;
    }
}
