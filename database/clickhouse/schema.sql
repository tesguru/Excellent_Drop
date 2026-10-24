-- DropWatch ClickHouse schema (database: zones)
--
-- Applied by `php artisan zones:ch-migrate`. Everything is IF NOT EXISTS so
-- the command is safe to run repeatedly.

CREATE DATABASE IF NOT EXISTS zones;

-- Snapshot of every second-level label seen in each zone file, per day.
-- PARTITION BY snapshot_date lets us drop old days with a single statement.
CREATE TABLE IF NOT EXISTS zones.zone_names
(
    `snapshot_date` Date,
    `tld` LowCardinality(String),
    `name` String,
    `name_rev` String,
    `ns` String DEFAULT '',
    INDEX name_ngram name TYPE ngrambf_v1(3, 65536, 2, 0) GRANULARITY 4,
    -- Suffix searches use the reversed name. Ordering a copy by
    -- (tld, name_rev) turns them into a primary-key range.
    PROJECTION name_rev_order (SELECT * ORDER BY (tld, name_rev))
)
ENGINE = MergeTree
PARTITION BY snapshot_date
ORDER BY (tld, name);

-- Existing installations need the projection added explicitly; CREATE TABLE
-- IF NOT EXISTS does not modify an existing table. `zones:ch-migrate`
-- materializes it for parts that were written before the projection existed.
ALTER TABLE zones.zone_names ADD PROJECTION IF NOT EXISTS name_rev_order (SELECT * ORDER BY (tld, name_rev));

-- Permanent log of names appearing in / disappearing from a zone.
CREATE TABLE IF NOT EXISTS zones.zone_events
(
    `event_date` Date,
    `tld` LowCardinality(String),
    `name` String,
    `event` Enum8('removed' = 1, 'added' = 2)
)
ENGINE = MergeTree
ORDER BY (event_date, tld, name);

-- One row per removed .com name that still has a weaker version registered.
CREATE TABLE IF NOT EXISTS zones.com_candidates
(
    `event_date` Date,
    `name` String,
    `weaker_found` Array(String),
    `weaker_types` Array(String),
    `weaker_count` UInt32,
    `tld_count` UInt8,
    `exact_tld_count` UInt16 DEFAULT 0,
    `is_short` UInt8 DEFAULT 0,
    `score` Float32
)
ENGINE = MergeTree
ORDER BY (event_date, score);

-- Existing installations need the column added explicitly: CREATE TABLE IF
-- NOT EXISTS does not modify a table that already exists.
ALTER TABLE zones.com_candidates ADD COLUMN IF NOT EXISTS `is_short` UInt8 DEFAULT 0;
ALTER TABLE zones.com_candidates ADD COLUMN IF NOT EXISTS `exact_tld_count` UInt16 DEFAULT 0;
