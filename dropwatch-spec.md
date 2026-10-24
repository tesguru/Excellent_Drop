# DropWatch specification

DropWatch watches ICANN CZDS zone files for `.com` names that were removed
from the zone and ranks the weaker versions of those names. A removed name is
never reported as available: it is in redemption or pending delete, and the
app only shows an estimated drop date.

## Network policy

DropWatch makes exactly two kinds of HTTP requests:

1. The CZDS downloader (`app/Services/Czds/CzdsClient.php`) talking to ICANN.
2. The ClickHouse client (`app/Services/ClickHouse/ClickHouseClient.php`)
   talking to the configured ClickHouse host, including the HTTP interface
   used for small queries.

No other outbound HTTP is allowed. There is no RDAP client, IANA bootstrap
request, website checker or live DNS lookup. RDAP links in the dashboard are
rendered from `LOOKUP_LINK_TEMPLATE` for a human to open in a new tab; the
application never requests them. `tests/Unit/NetworkPolicyTest.php` fails if
an HTTP call appears outside the two allowed clients.

## Storage

PostgreSQL holds the application tables, the queue, job runs, users,
watchlist entries and the zone registry.

ClickHouse (database `zones` by default) holds:

* `zone_names` — one row per second-level name per TLD and snapshot date,
  with `name_rev` for suffix searches and `ns` with the nameservers
  aggregated from the zone file.
* `zone_events` — `added` and `removed` events produced by the diff.
* `com_candidates` — one row per removed `.com` name with a weaker version.

ClickHouse statements run through `clickhouse-client`; small lookups use the
HTTP interface.

## Pipeline

`download → extract → load → diff → candidates → cleanup`

* **Download** asks CZDS for the zone list and checks `Last-Modified` with a
  HEAD request. A file older than 20 hours is not downloaded: the step waits
  30 minutes and retries, up to four times, then fails with a clear message.
* **Extract** streams the gzip file through awk and sort, producing one
  `<label>\t<nameservers>` line per second-level name. The raw zone may be
  deleted when `ZONE_KEEP_RAW=false`.
* **Load** streams the extracted TSV into `zone_names` through
  `clickhouse-client`; rows never pass through a PHP array.
* **Diff** compares consecutive snapshots on disk and writes `zone_events`.
* **Candidates** runs only for names removed today.
* **Cleanup** removes raw files and expired snapshots.

The first day has no previous snapshot, so it records the baseline only: no
diff and no candidate search.

## Candidate rules

For each `.com` name removed today, DropWatch searches the latest snapshots of
all TLDs (including `.com`) for:

* the same name on another TLD (`other_tld`), excluding the dropped name
  itself;
* a hyphen inserted at any split point (`hyphen`);
* extra characters before the name (`starts_with`);
* extra characters after the name (`ends_with`, through `name_rev`);
* a plural or singular form (`plural`).

Misspellings and contains-in-the-middle are disabled unless
`WEAKER_PHASE2_ENABLED=true`.

Names of four characters or fewer keep `other_tld`, `hyphen` and `plural`
without restriction; `starts_with` and `ends_with` may add at most two
characters and the candidate keeps at most 20 weaker versions.

A removed name that is present again in today's `.com` snapshot is restored
and is not searched. A candidate is hidden again if an `added` event for the
same `.com` name appears after the removal.

Every candidate and every weaker domain renders an RDAP link built locally
from `LOOKUP_LINK_TEMPLATE` (default `https://rdap.org/domain/{domain}`).
Links open in a new tab with `rel="noopener noreferrer"`.

## Scoring

The score combines:

* number of weaker versions and how close they are (fewer added characters
  ranks higher);
* `exact_tld_count`, the number of other TLDs where the exact same name is
  registered; it adds a bonus at two or more TLDs unless every one of those
  domains shares the same nameservers;
* name quality: length, a single word or word pair, no digits and no hyphen;
* independent owners versus one owner, using the nameservers in the zone
  snapshots. Missing nameserver data is unknown and never hides a candidate;
* a short-name bonus, a short badge and a short filter.

`brand_risk` comes only from `storage/app/brand_terms.txt`; there is no
dictionary heuristic.

Strategy 1 (same name elsewhere / variants) and strategy 2 (the same name on
other TLDs) are shown as one combined list, with an `exact_tld_count` column
and a “2+ endings” filter.

## Processing limits

* Removed names are streamed from ClickHouse in chunks of 5,000; a full day is
  never loaded into one PHP array.
* `WeakerSearcher` receives the chunk and runs one batched query per rule,
  passing the names as `Array(String)`; there is no query per name.
* Candidates are inserted in batches of 1,000 after each chunk. A rerun
  deletes only today's `event_date`, never older dates.
* Progress, chunk duration and totals are recorded on the `job_runs` row and
  logged once per chunk.
* The zones queue runs a single worker and `retry_after` is longer than the
  longest step.

## Search

`/search` queries `zone_names` in the latest snapshot for every TLD with
Contains, Starts with, Ends with and Exact modes, plus TLD, length, digit and
hyphen filters. Queries are parameterised; `%` and `_` are escaped. Contains
requires at least three characters, runs with a 20 second timeout and reports
“too broad” instead of hanging. Results are limited to 100 per page with a
hard cap of 5,000 and the search input debounces for 400ms.

Every result shows whether the name was removed or added in the last 45 days
and links to RDAP.

## Schedule and retention

CZDS publishes after 06:00 UTC. The default schedule is 08:00 UTC (09:00 in
Africa/Lagos) with an explicit scheduler timezone. `ZONE_RETAIN_DAYS` defaults
to 2 and raw files are not kept by default.

## Security

The dashboard is single-user and behind authentication. `/login` is rate
limited. ClickHouse uses a dedicated user restricted to the `zones` database.

## Tests

Unit and feature tests run without ClickHouse. Tests that need ClickHouse
state it explicitly and are executed on the VPS against a separate
`zones_test` database, never the real `zones` database. Performance changes
to suffix searches are verified with `EXPLAIN` output.
