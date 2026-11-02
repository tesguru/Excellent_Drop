-- DropWatch ClickHouse schema (database: zones)
--
-- Applied by `php artisan zones:ch-migrate`. Tables are IF NOT EXISTS and the
-- corpus view is replaceable so the command is safe to run repeatedly.

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
-- NOT EXISTS does not modify a table that already exists. Rows written before
-- the PendingDelete source keep tld='com' and an empty status; both the empty
-- status and 'dropped' are drop candidates, 'pending_delete' rows are the
-- pending candidates for names heading for deletion.
ALTER TABLE zones.com_candidates ADD COLUMN IF NOT EXISTS `is_short` UInt8 DEFAULT 0;
ALTER TABLE zones.com_candidates ADD COLUMN IF NOT EXISTS `exact_tld_count` UInt16 DEFAULT 0;
ALTER TABLE zones.com_candidates ADD COLUMN IF NOT EXISTS `tld` LowCardinality(String) DEFAULT 'com';
ALTER TABLE zones.com_candidates ADD COLUMN IF NOT EXISTS `status` LowCardinality(String) DEFAULT '';
ALTER TABLE zones.com_candidates ADD COLUMN IF NOT EXISTS `drop_utc` Nullable(DateTime('UTC'));

-- ---------------------------------------------------------------------------
-- PendingDelete.net
--
-- One row per normalized domain identity (tld + label). ReplacingMergeTree
-- keeps the highest version, so re-importing the same dataset is idempotent
-- and a status change never creates a second identity. The label is lower
-- case; the TLD is the suffix from the dataset file.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS zones.pendingdelete_names
(
    `tld` LowCardinality(String),
    `name` String,
    `label_length` UInt8,
    `status` LowCardinality(String),
    `source_list` LowCardinality(String),
    `drop_utc` Nullable(DateTime('UTC')),
    `registrar` String DEFAULT '',
    `built_utc` DateTime('UTC'),
    `observed_at` DateTime64(3, 'UTC'),
    `version` UInt64
)
ENGINE = ReplacingMergeTree(version)
ORDER BY (tld, name);

-- Append-only record of every status transition. A row is written in the same
-- operation as the state change, before the current-state row is replaced.
CREATE TABLE IF NOT EXISTS zones.pendingdelete_status_history
(
    `tld` LowCardinality(String),
    `name` String,
    `status` LowCardinality(String),
    `source_list` LowCardinality(String),
    `drop_utc` Nullable(DateTime('UTC')),
    `built_utc` DateTime('UTC'),
    `observed_at` DateTime64(3, 'UTC')
)
ENGINE = MergeTree
ORDER BY (tld, name, observed_at);

-- A dataset is written here first. It is merged into the current state only
-- after the whole file parsed and its declared row count matched. The TTL
-- removes the rows of an import that died before cleanup.
CREATE TABLE IF NOT EXISTS zones.pendingdelete_staging
(
    `import_id` String,
    `tld` LowCardinality(String),
    `name` String,
    `label_length` UInt8,
    `status` LowCardinality(String),
    `source_list` LowCardinality(String),
    `drop_utc` Nullable(DateTime('UTC')),
    `registrar` String DEFAULT '',
    `built_utc` DateTime('UTC'),
    `observed_at` DateTime64(3, 'UTC'),
    `version` UInt64
)
ENGINE = MergeTree
ORDER BY (import_id, tld, name)
TTL observed_at + INTERVAL 6 HOUR DELETE;

-- Identities already searched for weaker versions, including names that had
-- none. A 10-minute import only searches the names that appeared since the
-- previous run; rebuilding one date clears its rows first. The mode keeps
-- pending and dropped scoring independent: a name scored while pending is
-- searched again when it drops.
CREATE TABLE IF NOT EXISTS zones.pendingdelete_scored
(
    `event_date` Date,
    `tld` LowCardinality(String),
    `name` String,
    `scored_at` DateTime64(3, 'UTC')
)
ENGINE = ReplacingMergeTree(scored_at)
ORDER BY (event_date, tld, name);

-- Rows written before the pending mode existed are dropped-mode scores.
ALTER TABLE zones.pendingdelete_scored ADD COLUMN IF NOT EXISTS `mode` LowCardinality(String) DEFAULT 'dropped';

-- The corpus the weaker-version search reads. It is the newest zone snapshot
-- plus every name that is currently registered, pending delete or in
-- redemption. Dropped names are excluded because they are no longer
-- registrations. This keeps TLD comparison working without downloading CZDS
-- files every day.
--
-- This is the starting definition: it takes the single newest snapshot date,
-- so a TLD whose last load was earlier drops out of the corpus. `php artisan
-- zones:refresh-corpus` rewrites the view with each TLD pinned to its own
-- newest snapshot as literals, which is what keeps partition pruning and the
-- (tld, name) primary key working.
--
-- The zone branch is wrapped in a subquery: naming the output column
-- snapshot_date in the same SELECT would shadow the zone_names column, so the
-- filter would compare today's date against the newest snapshot and match
-- nothing.
CREATE OR REPLACE VIEW zones.weaker_corpus AS
SELECT name, name_rev, tld, ns, toDate(now('UTC')) AS snapshot_date
FROM (
    SELECT name, name_rev, tld, ns
    FROM zones.zone_names
    WHERE snapshot_date = (SELECT max(snapshot_date) FROM zones.zone_names)
)
UNION ALL
SELECT name, reverse(name) AS name_rev, tld, '' AS ns, toDate(now('UTC')) AS snapshot_date
FROM zones.pendingdelete_names FINAL
WHERE status != 'dropped';
