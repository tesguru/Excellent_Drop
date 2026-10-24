<?php

namespace App\Services;

use App\Models\Watchlist;
use App\Services\ClickHouse\ClickHouseClient;
use Illuminate\Support\Carbon;

/**
 * Read side of the candidate data.
 *
 * The candidates live in ClickHouse and the watchlist lives in Postgres, so
 * every read is decorated with the watch flag and a locally calculated drop
 * estimate.
 */
class CandidateRepository
{
    public function __construct(
        private readonly ClickHouseClient $clickhouse,
        private readonly string $database = 'zones',
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forDate(string $date, int $limit = 500, bool $shortOnly = false): array
    {
        $sql = $this->visibleCandidatesSql(
            'SELECT c.event_date, c.name, c.weaker_found, c.weaker_types, c.weaker_count, c.tld_count, c.is_short, c.score',
            'c.event_date = {d:Date}'.($shortOnly ? ' AND c.is_short = 1' : ''),
            'ORDER BY c.score DESC, c.name LIMIT {lim:UInt32}',
        );

        $rows = $this->clickhouse->rows($sql, [
            'd' => $date,
            'from' => $date,
            'tld' => 'com',
            'event' => 'added',
            'lim' => $limit,
        ]);

        return $this->decorate($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forRange(string $from, string $to, int $limit = 1000): array
    {
        $rows = $this->clickhouse->rows(
            $this->visibleCandidatesSql(
                'SELECT c.event_date, c.name, c.weaker_found, c.weaker_types, c.weaker_count, c.tld_count, c.is_short, c.score',
                'c.event_date >= {from:Date} AND c.event_date <= {to:Date}',
                'ORDER BY c.event_date DESC, c.score DESC, c.name LIMIT {lim:UInt32}',
            ),
            ['from' => $from, 'to' => $to, 'tld' => 'com', 'event' => 'added', 'lim' => $limit],
        );

        return $this->decorate($rows);
    }

    /**
     * Search candidates by name. An exact term is matched first, a partial
     * term matches anywhere in the name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term, int $limit = 200): array
    {
        $term = strtolower(trim($term));

        if ($term === '') {
            return [];
        }

        // A full domain is searched on its second level label.
        if (str_contains($term, '.')) {
            $term = (string) preg_replace('/\.[^.]+$/', '', $term);
        }

        if ($term === '') {
            return [];
        }

        $from = now()->subDays((int) config('zones.retain_days', 45))->format('Y-m-d');

        $rows = $this->clickhouse->rows(
            $this->visibleCandidatesSql(
                'SELECT c.event_date, c.name, c.weaker_found, c.weaker_types, c.weaker_count, c.tld_count, c.is_short, c.score',
                'c.event_date >= {from:Date} '
                .'AND (c.name = {exact:String} OR positionCaseInsensitive(c.name, {partial:String}) > 0)',
                'ORDER BY c.event_date DESC, c.score DESC, c.name LIMIT {lim:UInt32}',
            ),
            [
                'from' => $from,
                'exact' => $term,
                'partial' => $term,
                'tld' => 'com',
                'event' => 'added',
                'lim' => $limit,
            ],
        );

        return $this->decorate($rows);
    }

    /**
     * Names that left the .com zone on a given day.
     *
     * @return array<int, array<string, mixed>>
     */
    public function removedNames(string $date, int $limit = 2000): array
    {
        $rows = $this->clickhouse->rows(
            sprintf(
                'SELECT event_date, name FROM %s.zone_events '
                .'WHERE tld = {tld:String} AND event = {event:String} AND event_date = {d:Date} '
                .'ORDER BY name LIMIT {lim:UInt32}',
                $this->database,
            ),
            ['tld' => 'com', 'event' => 'removed', 'd' => $date, 'lim' => $limit],
        );

        $names = [];

        foreach ($rows as $row) {
            $names[] = (string) ($row['name'] ?? '');
        }

        return $names;
    }

    /**
     * Names that appeared in the .com zone on a given day.
     *
     * @return array<int, string>
     */
    public function addedNames(string $date, int $limit = 2000): array
    {
        $rows = $this->clickhouse->rows(
            sprintf(
                'SELECT name FROM %s.zone_events '
                .'WHERE tld = {tld:String} AND event = {event:String} AND event_date = {d:Date} '
                .'ORDER BY name LIMIT {lim:UInt32}',
                $this->database,
            ),
            ['tld' => 'com', 'event' => 'added', 'd' => $date, 'lim' => $limit],
        );

        $names = [];

        foreach ($rows as $row) {
            $names[] = (string) ($row['name'] ?? '');
        }

        return $names;
    }

    /**
     * How many names left the .com zone between two dates.
     */
    public function removedCount(string $from, string $to): int
    {
        $rows = $this->clickhouse->rows(
            sprintf(
                'SELECT count() AS total FROM %s.zone_events '
                .'WHERE tld = {tld:String} AND event = {event:String} '
                .'AND event_date >= {from:Date} AND event_date <= {to:Date}',
                $this->database,
            ),
            ['tld' => 'com', 'event' => 'removed', 'from' => $from, 'to' => $to],
        );

        return (int) ($rows[0]['total'] ?? 0);
    }

    /**
     * @return array<string, int>
     */
    public function countsForDate(string $date): array
    {
        $rows = $this->clickhouse->rows(
            sprintf(
                'SELECT event, count() AS total FROM %s.zone_events '
                .'WHERE tld = {tld:String} AND event_date = {d:Date} GROUP BY event',
                $this->database,
            ),
            ['tld' => 'com', 'd' => $date],
        );

        $counts = ['removed' => 0, 'added' => 0, 'candidates' => 0];

        foreach ($rows as $row) {
            $counts[(string) ($row['event'] ?? '')] = (int) ($row['total'] ?? 0);
        }

        $candidateRows = $this->clickhouse->rows(
            $this->visibleCandidatesSql(
                'SELECT count() AS total',
                'c.event_date = {d:Date}',
                '',
            ),
            ['d' => $date, 'from' => $date, 'tld' => 'com', 'event' => 'added'],
        );

        $counts['candidates'] = (int) ($candidateRows[0]['total'] ?? 0);

        return $counts;
    }

    /**
     * The TLDs that have a snapshot for a date.
     *
     * @return array<int, string>
     */
    public function tldsForDate(string $date): array
    {
        $rows = $this->clickhouse->rows(
            sprintf('SELECT DISTINCT tld FROM %s.zone_names WHERE snapshot_date = {d:Date} ORDER BY tld', $this->database),
            ['d' => $date],
        );

        $tlds = [];

        foreach ($rows as $row) {
            $tlds[] = (string) ($row['tld'] ?? '');
        }

        return $tlds;
    }

    /**
     * The days that have candidate data, newest first.
     *
     * @return array<int, string>
     */
    public function candidateDates(int $limit = 60): array
    {
        $rows = $this->clickhouse->rows(
            sprintf(
                'SELECT DISTINCT event_date FROM %s.com_candidates ORDER BY event_date DESC LIMIT {lim:UInt32}',
                $this->database,
            ),
            ['lim' => $limit],
        );

        $dates = [];

        foreach ($rows as $row) {
            $dates[] = (string) ($row['event_date'] ?? '');
        }

        return $dates;
    }

    /**
     * A candidate disappears once the same .com name is added again. The
     * join runs in ClickHouse so the limit still applies to visible rows.
     */
    private function visibleCandidatesSql(string $select, string $where, string $tail): string
    {
        return $select
            .' FROM '.$this->database.'.com_candidates AS c '
            .'LEFT JOIN ('
            .'SELECT name, max(event_date) AS added_on FROM '.$this->database.'.zone_events '
            .'WHERE tld = {tld:String} AND event = {event:String} AND event_date >= {from:Date} '
            .'GROUP BY name'
            .') AS restored ON restored.name = c.name '
            .'WHERE '.$where
            .' AND (restored.added_on IS NULL OR restored.added_on < c.event_date) '
            .$tail
            .' SETTINGS join_use_nulls = 1';
    }

    /**
     * Add the watchlist flag and the local drop estimate to the ClickHouse
     * rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function decorate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $domains = array_map(fn (array $row) => ((string) ($row['name'] ?? '')).'.com', $rows);

        $watched = Watchlist::whereIn('domain', $domains)->pluck('domain')->flip();

        $graceDays = (int) config('zones.drop.redemption_days', 30)
            + (int) config('zones.drop.pending_delete_days', 5);

        foreach ($rows as $index => $row) {
            $domain = ((string) ($row['name'] ?? '')).'.com';
            $eventDate = (string) ($row['event_date'] ?? '');

            $rows[$index]['domain'] = $domain;
            $rows[$index]['weaker_found'] = array_values((array) ($row['weaker_found'] ?? []));
            $rows[$index]['weaker_types'] = array_values((array) ($row['weaker_types'] ?? []));
            $rows[$index]['is_short'] = (bool) ($row['is_short'] ?? false);
            $rows[$index]['estimated_drop_date'] = $eventDate === ''
                ? null
                : Carbon::parse($eventDate)->addDays($graceDays)->format('Y-m-d');
            $rows[$index]['watchlisted'] = $watched->has($domain);
        }

        return $rows;
    }
}
