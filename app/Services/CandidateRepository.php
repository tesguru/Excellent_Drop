<?php

namespace App\Services;

use App\Models\RdapStatus;
use App\Models\Watchlist;
use App\Services\ClickHouse\ClickHouseClient;

/**
 * Read side of the candidate data.
 *
 * The candidates live in ClickHouse, the RDAP status and the watchlist live
 * in Postgres, so every read is decorated with both.
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
        $sql = sprintf(
            'SELECT event_date, name, weaker_found, weaker_types, weaker_count, tld_count, is_short, score '
            .'FROM %s.com_candidates WHERE event_date = {d:Date}',
            $this->database,
        );

        if ($shortOnly) {
            $sql .= ' AND is_short = 1';
        }

        $sql .= ' ORDER BY score DESC, name LIMIT {lim:UInt32}';

        $rows = $this->clickhouse->rows($sql, ['d' => $date, 'lim' => $limit]);

        return $this->decorate($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forRange(string $from, string $to, int $limit = 1000): array
    {
        $rows = $this->clickhouse->rows(
            sprintf(
                'SELECT event_date, name, weaker_found, weaker_types, weaker_count, tld_count, is_short, score '
                .'FROM %s.com_candidates WHERE event_date >= {from:Date} AND event_date <= {to:Date} '
                .'ORDER BY event_date DESC, score DESC, name LIMIT {lim:UInt32}',
                $this->database,
            ),
            ['from' => $from, 'to' => $to, 'lim' => $limit],
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
            sprintf(
                'SELECT event_date, name, weaker_found, weaker_types, weaker_count, tld_count, is_short, score '
                .'FROM %s.com_candidates WHERE event_date >= {from:Date} '
                .'AND (name = {exact:String} OR positionCaseInsensitive(name, {partial:String}) > 0) '
                .'ORDER BY event_date DESC, score DESC, name LIMIT {lim:UInt32}',
                $this->database,
            ),
            ['from' => $from, 'exact' => $term, 'partial' => $term, 'lim' => $limit],
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
            sprintf('SELECT count() AS total FROM %s.com_candidates WHERE event_date = {d:Date}', $this->database),
            ['d' => $date],
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
     * Add the RDAP status and the watchlist flag to the ClickHouse rows.
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

        $statuses = RdapStatus::whereIn('domain', $domains)->get()->keyBy('domain');
        $watched = Watchlist::whereIn('domain', $domains)->pluck('domain')->flip();

        foreach ($rows as $index => $row) {
            $domain = ((string) ($row['name'] ?? '')).'.com';
            $status = $statuses->get($domain);

            $rows[$index]['domain'] = $domain;
            $rows[$index]['weaker_found'] = array_values((array) ($row['weaker_found'] ?? []));
            $rows[$index]['weaker_types'] = array_values((array) ($row['weaker_types'] ?? []));
            $rows[$index]['is_short'] = (bool) ($row['is_short'] ?? false);
            $rows[$index]['rdap_status'] = $status?->status;
            $rows[$index]['predicted_drop_date'] = $status?->predicted_drop_date?->format('Y-m-d');
            $rows[$index]['rdap_checked_at'] = $status?->last_checked?->format('Y-m-d H:i');
            $rows[$index]['restored'] = (bool) ($status?->restored ?? false);
            $rows[$index]['watchlisted'] = $watched->has($domain);
        }

        return $rows;
    }
}
