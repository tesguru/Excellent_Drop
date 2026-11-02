<?php

namespace App\Console\Commands;

use App\Services\ClickHouse\ClickHouseClient;
use Illuminate\Console\Command;

/**
 * Rewrite the weaker_corpus view so every TLD is read at its own newest
 * snapshot.
 *
 * The corpus cannot express this as SQL: a join or a tuple IN subquery that
 * resolves the newest date per TLD at run time makes ClickHouse read every
 * part of zone_names, which measured 26s per query. Writing the dates into the
 * view as literals keeps partition pruning and the (tld, name) primary key, and
 * the same lookup answers in about 0.4s.
 */
class RefreshCorpusView extends Command
{
    protected $signature = 'zones:refresh-corpus';

    protected $description = 'Pin every TLD of the weaker-version corpus to its newest zone snapshot';

    public function handle(ClickHouseClient $clickhouse): int
    {
        $database = (string) config('zones.clickhouse.database', 'zones');

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $database) !== 1) {
            $this->error("Invalid ClickHouse database name: {$database}");

            return self::FAILURE;
        }

        $newest = $this->newestSnapshotPerTld($clickhouse);

        $clickhouse->execute($this->viewSql($database, $newest));

        if ($newest === []) {
            $this->info('Corpus refreshed: no zone snapshot yet, the corpus is the PendingDelete state only.');

            return self::SUCCESS;
        }

        $byDate = [];

        foreach ($newest as $tld => $date) {
            $byDate[$date][] = $tld;
        }

        krsort($byDate);

        foreach ($byDate as $date => $tlds) {
            $this->line(sprintf('%s  %d TLD(s): %s', $date, count($tlds), implode(', ', $tlds)));
        }

        $this->info('Corpus refreshed with '.count($newest).' TLD(s).');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string> tld => newest snapshot date
     */
    private function newestSnapshotPerTld(ClickHouseClient $clickhouse): array
    {
        $rows = $clickhouse->rows(
            sprintf(
                'SELECT tld, toString(max(snapshot_date)) AS latest FROM %s.zone_names GROUP BY tld',
                $clickhouse->database(),
            ),
        );

        $newest = [];

        foreach ($rows as $row) {
            $tld = strtolower(trim((string) ($row['tld'] ?? '')));
            $date = trim((string) ($row['latest'] ?? ''));

            if ($tld !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $newest[$tld] = $date;
            }
        }

        return $newest;
    }

    /**
     * @param  array<string, string>  $newest  tld => snapshot date
     */
    private function viewSql(string $database, array $newest): string
    {
        $byDate = [];

        foreach ($newest as $tld => $date) {
            $byDate[$date][] = $tld;
        }

        $clauses = [];

        foreach ($byDate as $date => $tlds) {
            $clauses[] = '(tld IN '.json_encode(array_values($tlds), JSON_THROW_ON_ERROR)
                .' AND snapshot_date = '.$this->sqlString($date).')';
        }

        // An empty zone table leaves nothing to pin; the corpus is then only
        // the PendingDelete registrations.
        $zoneFilter = $clauses === [] ? '0' : implode(' OR ', $clauses);

        return sprintf(
            'CREATE OR REPLACE VIEW %s.weaker_corpus AS '
            .'SELECT name, name_rev, tld, ns, toDate(now(\'UTC\')) AS snapshot_date '
            .'FROM (SELECT name, name_rev, tld, ns FROM %s.zone_names WHERE (%s)) '
            .'UNION ALL '
            ."SELECT name, reverse(name) AS name_rev, tld, '' AS ns, toDate(now('UTC')) AS snapshot_date "
            ."FROM %s.pendingdelete_names FINAL WHERE status != 'dropped'",
            $this->quoteIdentifier($database),
            $this->quoteIdentifier($database),
            $zoneFilter,
            $this->quoteIdentifier($database),
        );
    }

    private function quoteIdentifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private function sqlString(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
