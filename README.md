# DropWatch

DropWatch watches the PendingDelete.net datasets for names that are in
redemption, pending delete or already dropped, and looks for weaker versions
of the dropped names on the other supported TLDs. It imports all 15 supported
TLDs, not just `.com`, and keeps the lifecycle status of every domain with a
timestamp for each change.

The dashboard is private and single user. It is a Laravel application with
Blade, vanilla JavaScript and a Vite/Tailwind build.

## What it does

1. Reads the PendingDelete.net manifest. It lists 45 files: three lists
   (`dropping-today`, `pending`, `dropped-yesterday`) for each of the 15
   supported TLDs. The manifest is rebuilt roughly every ten minutes in
   America/New_York; the files are downloaded over HTTPS as JSON with
   `status,length,drop,registrar` metrics added.
2. Streams every file row by row into `pendingdelete_staging`. A truncated or
   malformed file is rejected before anything is merged, so a failed download
   never changes the current state.
3. Merges a complete file into `pendingdelete_names` (a ReplacingMergeTree
   keyed by `(tld, name)`) and appends every status change to
   `pendingdelete_status_history`. The identity is the lower-case label plus
   the TLD, so the same domain is never stored twice and repeated imports are
   idempotent. Missing rows are never deleted.
4. Searches every TLD in the shared corpus for weaker versions of every
   dropped name: the same name on another TLD, the same name with a hyphen, a
   name that starts with it, a name that ends with it and the plural of it.
   The dropped domain's own TLD and identity are excluded. Names of four
   characters or fewer use a restricted rule set: prefix and suffix matches
   may only add one or two characters and at most 20 weaker versions are kept.
5. Scores every candidate from the weaker versions, how many other TLDs carry
   the exact same name (`exact_tld_count`) and the nameserver data in the
   corpus. The exact-name bonus needs two or more TLDs and is suppressed when
   every domain shares the same nameservers. It never performs RDAP or website
   lookups. The candidate build is incremental: a 10-minute import only
   searches the identities that appeared since the previous run.
6. The comparison corpus is `weaker_corpus`: every TLD's newest zone snapshot
   plus every name currently registered, pending delete or in redemption. The
   zone pipeline is no longer scheduled; it remains available for manual
   backfills with `php artisan zones:run`, and `php artisan
   zones:refresh-corpus` folds whatever snapshots exist into the corpus.

## Network policy

DropWatch makes exactly three kinds of HTTP calls:

1. The PendingDelete.net downloader
   (`app/Services/PendingDelete/PendingDeleteClient.php`) reading the manifest
   and the dataset files over HTTPS.
2. The CZDS downloader (`app/Services/Czds/CzdsClient.php`) talking to ICANN
   for manual zone backfills.
3. The ClickHouse client (`app/Services/ClickHouse/ClickHouseClient.php`)
   talking to the configured ClickHouse host.

Everything else is local. RDAP links and the "Check status" action are
rendered for the browser: when a user clicks the button the browser queries
the registry directly, and the DropWatch server never sees or makes that
request. There is no server-side RDAP client, site checker, DNS lookup or IANA
bootstrap request.

## Requirements

* PHP 8.3 or newer with `pdo_pgsql`, `curl` and `mbstring`
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

# Create the ClickHouse tables (zone corpus, PendingDelete state and
# candidates). The database and the app user are created once by an
# administrator, see "ClickHouse access":
php artisan zones:ch-migrate

# Create the single dashboard user:
php artisan dropwatch:user owner@example.com

# The worker and the scheduler (one zones worker only):
php artisan queue:work zones --queue=zones
php artisan schedule:work
```

No credentials are needed for PendingDelete.net. Fill in `CZDS_USERNAME` and
`CZDS_PASSWORD` in `.env` only if you want to run a manual zone backfill.

### ClickHouse tables

`database/clickhouse/schema.sql` holds the schema. It is also applied by
`php artisan zones:ch-migrate`, which is safe to run again.

| Table | Purpose |
| --- | --- |
| `zone_names` | One row per name per zone per snapshot date, with a reversed name column, an ngram index for prefix lookups and a `name_rev_order` projection for suffix lookups |
| `zone_events` | Historical zone diff, kept for the legacy daily counts |
| `com_candidates` | One row per dropped name with weaker versions; `tld`, `status` and `drop_utc` identify the source identity |
| `pendingdelete_names` | Current state of every imported domain, one row per `(tld, name)` |
| `pendingdelete_status_history` | Append-only log of every status transition with its observation time |
| `pendingdelete_staging` | Per-import buffer, removed after a successful merge and by TTL after 6 hours |
| `pendingdelete_scored` | Identities already searched for weaker versions, so incremental runs do not repeat work |
| `weaker_corpus` (view) | Every name still registered, pending delete or in redemption, plus each TLD's newest zone snapshot as pinned by `zones:refresh-corpus` |

### ClickHouse access

The application should not connect as `default`. Create the database and an
account that can only touch it; run this once as an administrator:

```sql
CREATE DATABASE IF NOT EXISTS zones;
CREATE USER IF NOT EXISTS dropwatch IDENTIFIED WITH sha256_password BY '<strong-password>';
GRANT SELECT, INSERT, ALTER, CREATE TABLE, DROP TABLE, TRUNCATE, OPTIMIZE ON zones.* TO dropwatch;
GRANT SELECT ON system.databases TO dropwatch;
GRANT SELECT ON system.parts TO dropwatch;
GRANT SELECT ON system.projection_parts TO dropwatch;
```

Set `CLICKHOUSE_USER=dropwatch` and `CLICKHOUSE_PASSWORD` in `.env`, then run
`php artisan zones:ch-migrate`. It creates the tables and the projection
definition but never creates the database when it already exists. The
`system` grants are read-only metadata used to detect the database. The
account has no access to any other database.

## Commands

| Command | What it does |
| --- | --- |
| `php artisan pendingdelete:sync --list=all` | Queue the imports for one list or all three (`--force` reimports a version that already succeeded) |
| `php artisan zones:run` | Queue the full CZDS pipeline for a manual backfill |
| `php artisan zones:step {step} [--tld=]` | Queue one CZDS step: `download`, `extract`, `load`, `diff`, `candidates` or `cleanup` |
| `php artisan zones:retry` | Requeue the failed steps of the last runs |
| `php artisan zones:clear-stuck` | Mark job runs that are stuck in `running` as failed |
| `php artisan zones:ch-migrate` | Create or update the ClickHouse tables and the corpus view |
| `php artisan zones:refresh-corpus` | Rewrite the corpus view with each TLD pinned to its own newest zone snapshot |
| `php artisan dropwatch:user {email}` | Create or update the dashboard user |

## Dashboard

| Page | What it shows |
| --- | --- |
| Overview | Dropped and pending-delete counts, today's candidates, the worker heartbeat, the last run and the zone access that expires soon |
| Daily list | Domains that dropped on a chosen day, the pending backlog and that day's candidates, each as its own tab |
| Search | Names across every TLD in the latest zone snapshot, with Contains, Starts with, Ends with and Exact modes |
| Watchlist | Domains to keep an eye on, with a status and notes |
| Jobs | Every step of every run with its status, duration and row counts |
| Zones | The zones the account has access to, when the access expires and whether a snapshot was loaded |

Every page has a JSON endpoint under `/api` that returns the same data.

### Search

The Search page reads the latest `zone_names` snapshot across every loaded
TLD. The modes are Contains, Starts with, Ends with (through `name_rev`) and
Exact; the filters cover one or more TLDs, minimum and maximum name length,
digits and hyphens. Every value is passed as a ClickHouse query parameter and
no query uses `LIKE`, so `%` and `_` are literal characters.

Contains needs at least three characters. A query is stopped after 20 seconds
and reported as "too broad", results are capped at 100 per page and 5,000 in
total, and typing debounces for 400ms. Each result shows whether the name was
removed or added in the last 45 days.

Ends with searches force the `name_rev_order` projection (ordered by
`(tld, name_rev)`), so ClickHouse reads the reversed-name order instead of
scanning every row. `zones:ch-migrate` adds the projection definition; pass
`--materialize` to rebuild existing parts once. It is off by default because
the rewrite covers every part of `zone_names`.

## Scheduling

One cron entry is enough:

```cron
* * * * * cd /var/www/dropwatch && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

The scheduler keeps the imports aligned with the manifest rebuild cadence
(all cron expressions are UTC, see `config/pendingdelete.php`):

| List | Default | Why |
| --- | --- | --- |
| `dropping-today` | `*/10 * * * *` | The actionable list; matches the ten-minute dataset rebuild |
| `pending` | `5 * * * *` | A large, slowly changing backlog |
| `dropped-yesterday` | `15 */6 * * *` | Complete once the day has closed |

Each command queues `ImportPendingDeleteList`; every TLD file is imported
independently, so one failed download does not stop the others. A successful
import of `dropping-today` or `dropped-yesterday` then queues
`BuildPendingDeleteCandidates` for the drop date. Candidate building is
incremental and records every searched identity in `pendingdelete_scored`, so
a ten-minute run only scores the names that are new.

The scheduler also runs the worker heartbeat every minute, the
`zones:clear-stuck` housekeeping command hourly, and `zones:refresh-corpus` at
00:20 UTC so a TLD whose zone file was loaded on an earlier date stays in the
corpus. The heartbeat is what the status light in the header shows, so a
stopped worker is visible at a glance.

### Retention

The dataset import never deletes `pendingdelete_names` rows just because a
domain is absent from a list. The status changes instead, and the transition
is logged. `ZONE_RETAIN_DAYS` still controls the CZDS snapshot retention when
the manual pipeline runs; candidate rows and the status history stay in
ClickHouse across snapshot pruning.

The staleness guard for a manual CZDS backfill can be tuned with
`ZONE_STALE_AFTER_HOURS` (20), `ZONE_STALE_RETRY_MINUTES` (30) and
`ZONE_STALE_MAX_RETRIES` (4).

### Candidate processing

One job handles one drop date. It streams the dropped identities grouped by
TLD, skips the identities already present in `pendingdelete_scored`, and runs
the weaker-version search in chunks of 5,000 with one batched ClickHouse query
per rule. The dropped domain's own TLD is excluded from the exact-name count.
Candidates are inserted in batches of 1,000 and the searched identities are
recorded in the same chunk, so a crash redoes at most one chunk. A `--force`
run deletes only that date's candidate rows and scored identities before it
rebuilds; historical days, the status history and the watchlist are never
touched.

The queue workers are supervisor programs, see
`deploy/supervisor/dropwatch-worker.conf`.

## Security

The dashboard is single user and every route except `/login` requires
authentication. `POST /login` accepts at most five attempts per minute from one
IP address; further attempts receive `429 Too Many Requests`.

## Naming and statuses

* A domain identity is the lower-case second-level label plus its TLD. The
  label is stored without the TLD (`acmeplumbing`), and `pendingdelete_names`
  is keyed by `(tld, name)`, so `acmeplumbing.com` and `acmeplumbing.net` are
  two identities and neither can be duplicated by a repeated import.
* The lifecycle status is `dropped`, `pending_delete`, `redemption`,
  `registered` or `unknown`. `registered` is a name the dropping-today file
  still lists as registered with no drop time, so it is never counted as
  pending or dropped. A dropped observation outranks a pending-delete one when
  lists overlap. Every change is appended to `pendingdelete_status_history`
  with the observation timestamp and the dataset `built_utc`.
* The pages group the lifecycle into two numbers: **Dropped** and **Pending
  delete** (pending delete plus redemption, when the source reports it).
* `com_candidates.tld` and `com_candidates.status` record which identity and
  lifecycle state produced the candidate. `com_candidates.name` holds only
  the label; the API and pages expose the full `domain`.
* `com_candidates.weaker_found` holds full domains, for example
  `acme-plumbing.net`.
* `com_candidates.weaker_types` is a parallel array: the signals of the
  version at the same position, joined with commas (`other_tld`, `hyphen`,
  `starts_with`, `ends_with`, `plural`).
* `com_candidates.exact_tld_count` is the number of other TLDs that carry the
  exact same name. The candidates list shows it as `N endings` and has a
  `2+ endings` filter.
* `drop_utc` is the actual drop timestamp when the dataset supplies it. When
  it does not (legacy rows), the estimated drop date is the removal date plus
  the redemption and pending-delete windows. It is an estimate, never a
  statement that the name is available.
* Owner independence is calculated from the `ns` column of the corpus.
  PendingDelete rows carry no nameservers, so missing data is treated as
  unknown and never hides a candidate.
* The watchlist stores full domains and is not modified by the importer, the
  candidate rebuild or the CZDS pipeline.

## Optional files

* `storage/app/brand_terms.txt` - one brand per line. A name in this list is
  always flagged for a manual brand review. This is the only brand-risk
  signal; a dictionary word is not flagged just for being a word.
* `storage/app/dictionary.txt` - one word per line. It is used only for name
  quality (single word versus word pair).

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `zcat: can't stat` | The zone file was removed by the cleanup job before the extraction ran, or `ZONES_DIR` points somewhere else. |
| `Extraction for {tld} produced no names` | The zone file layout changed. Run `php artisan zones:step extract --tld=com` and inspect the file. |
| The worker light is red | The queue worker is not running or the scheduler is not running. Check supervisor and the cron entry. |
| `clickhouse-client failed` | The binary is not on the PATH of the PHP process. Set `CLICKHOUSE_CLIENT_BIN` to the full path. |
| Ends with returns “Projection ... not used” | Run `php artisan zones:ch-migrate`, then add `--materialize` if the historical parts still lack the `name_rev_order` projection. |
