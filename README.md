# DropWatch

DropWatch watches the `.com` zone for names that disappear and then looks for
weaker versions of those names on other zones. A name that leaves the zone is
**never** reported as available: it is in redemption or pending delete, and
DropWatch only says when it is predicted to become available again.

The dashboard is private and single user. It is a plain Laravel application
with Blade, vanilla JavaScript and the Tailwind CDN, so there is no build step.

## What it does

1. Downloads the zone files the CZDS account has access to. ICANN publishes the
   files after 06:00 UTC, so the download step checks the `Last-Modified` header
   first: a file older than 20 hours is stale, and the step waits 30 minutes and
   retries (up to 4 times) instead of saving yesterday's file.
2. Extracts the second level names with a shell pipeline (`gzip | awk | sort`),
   never by loading a zone file into PHP.
3. Loads the names into ClickHouse as a daily snapshot.
4. Diffs the snapshot against the previous day to get the removed and the added
   names.
5. Searches the other zones for weaker versions of every removed `.com` name:
   the same name on another TLD, the same name with a hyphen, a name that
   starts with it, a name that ends with it and the plural of it. Names of four
   characters or fewer use a restricted rule set: prefix and suffix matches may
   only add one or two characters and at most 20 weaker versions are kept, so a
   short name cannot flood the candidate list.
6. Scores every candidate and checks the RDAP status of the dropped name.
7. Cleans up old snapshots and raw files.

## Requirements

* PHP 8.3 or newer with `pdo_pgsql`, `curl`, `mbstring` and `dns`
* PostgreSQL 14 or newer
* ClickHouse 24 or newer with the `clickhouse-client` binary on the PATH
* The GNU/BSD tools `gzip` (or `zcat`), `awk`, `sort` and `wc`
* An ICANN CZDS account with access to at least one zone

## Setup

```sh
composer install
cp .env.example .env
php artisan key:generate

# Create the Postgres database, then:
php artisan migrate --force

# Create the ClickHouse tables (zone_names, zone_events, com_candidates):
php artisan zones:ch-migrate

# Create the single dashboard user:
php artisan dropwatch:user owner@example.com

# The worker and the scheduler:
php artisan queue:work --queue=zones
php artisan schedule:work
```

Fill in `CZDS_USERNAME` and `CZDS_PASSWORD` in `.env` before the first run.

### ClickHouse tables

`database/clickhouse/schema.sql` holds the schema. It is also applied by
`php artisan zones:ch-migrate`, which is safe to run again.

| Table | Purpose |
| --- | --- |
| `zone_names` | One row per name per zone per snapshot date, with a reversed name column and an ngram index for prefix and suffix lookups |
| `zone_events` | One row per name that was added or removed on a date |
| `com_candidates` | One row per removed `.com` name that has weaker versions, with the versions, their signals and the score |

## Commands

| Command | What it does |
| --- | --- |
| `php artisan zones:run` | Queue the full pipeline for a new run id |
| `php artisan zones:step {step} [--tld=]` | Queue one step: `download`, `extract`, `load`, `diff`, `candidates`, `rdap` or `cleanup` |
| `php artisan zones:retry` | Requeue the failed steps of the last runs |
| `php artisan zones:clear-stuck` | Mark job runs that are stuck in `running` as failed |
| `php artisan zones:ch-migrate` | Create or update the ClickHouse tables |
| `php artisan dropwatch:user {email}` | Create or update the dashboard user |

## Dashboard

| Page | What it shows |
| --- | --- |
| Overview | Names removed, added and the candidates for today, the worker heartbeat, the last run and the zone access that expires soon |
| Daily list | Everything that left the `.com` zone on a chosen day, the names that came back and the candidates of that day |
| Search | A dropped name and its weaker versions over the retention window |
| Watchlist | Domains to keep an eye on, with a status and notes |
| Jobs | Every step of every run with its status, duration and row counts |
| Zones | The zones the account has access to, when the access expires and whether a snapshot was loaded |

Every page has a JSON endpoint under `/api` that returns the same data.

## Scheduling

One cron entry is enough:

```cron
* * * * * cd /var/www/dropwatch && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

The scheduler runs the daily pipeline (`ZONES_SCHEDULE`, a cron expression in
UTC, default `0 8 * * *` = 09:00 Africa/Lagos), the worker heartbeat every
minute and the housekeeping command hourly. The heartbeat is what the status
light in the header shows, so a stopped worker is visible at a glance.

The staleness guard can be tuned with `ZONE_STALE_AFTER_HOURS` (20),
`ZONE_STALE_RETRY_MINUTES` (30) and `ZONE_STALE_MAX_RETRIES` (4). The Zones page
shows the `Last-Modified` date of the file that was actually downloaded, so a
stale snapshot is never mistaken for a fresh one. Waiting can keep the job busy
for up to two hours, so the queue's `DB_QUEUE_RETRY_AFTER` is set to `10800`
(three hours) to stop the database queue re-delivering a job that is still
sleeping.

The queue worker itself is a supervisor program, see
`deploy/supervisor/dropwatch-worker.conf`.

## Naming and statuses

* `zone_names.name`, `zone_events.name` and `com_candidates.name` hold the
  second level label without the TLD, for example `acmeplumbing`.
* `com_candidates.weaker_found` holds full domains, for example
  `acme-plumbing.net`.
* `com_candidates.weaker_types` is a parallel array: the signals of the
  version at the same position, joined with commas (`other_tld`, `hyphen`,
  `starts_with`, `ends_with`, `plural`).
* A name that left the zone is in redemption for about 30 days and in pending
  delete for about 5 days. The predicted drop date is the removal date plus
  those days, and it is corrected with the RDAP pending delete date when the
  registry reports one.
* `likely_same_owner` is only calculated from the live nameservers of the
  weaker versions, never from the glue records in the zone file.

## Optional files

* `storage/app/brand_terms.txt` - one brand per line. A name in this list is
  always flagged for a manual brand review.
* `storage/app/dictionary.txt` - one word per line. A single dictionary word
  of four characters or more is flagged as a possible brand as well.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `zcat: can't stat` | The zone file was removed by the cleanup job before the extraction ran, or `ZONES_DIR` points somewhere else. |
| `Extraction for {tld} produced no names` | The zone file layout changed. Run `php artisan zones:step extract --tld=com` and inspect the file. |
| `No RDAP server known for {tld}` | The IANA bootstrap file was parsed while it was unavailable. Clear the cache with `php artisan cache:clear`. |
| The worker light is red | The queue worker is not running or the scheduler is not running. Check supervisor and the cron entry. |
| `clickhouse-client failed` | The binary is not on the PATH of the PHP process. Set `CLICKHOUSE_CLIENT_BIN` to the full path. |
