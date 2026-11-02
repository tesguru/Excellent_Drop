<?php

namespace App\Services;

use App\Models\RdapStatus;
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
    /**
     * Candidates are history and are kept after their snapshots are pruned.
     */
    private const SEARCH_LOOKBACK_DAYS = 45;

    /** The columns every candidate read returns. */
    private const CANDIDATE_COLUMNS = 'c.event_date, c.tld, c.name, c.status, '
        .'toDate(c.drop_utc) AS drop_date, c.weaker_found, c.weaker_types, '
        .'c.weaker_count, c.tld_count, c.exact_tld_count, c.is_short, c.score';

    /** @var array<int, string>|null */
    private ?array $hiddenNames = null;

    /** Whether the pending-delete dataset has replaced the zone diff counts. */
    private ?bool $pendingDeleteReady = null;

    public function __construct(
        private readonly ClickHouseClient $clickhouse,
        private readonly string $database = 'zones',
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forDate(string $date, int $limit = 500, bool $shortOnly = false, bool $exactOnly = false): array
    {
        $sql = $this->visibleCandidatesSql(
            'SELECT '.self::CANDIDATE_COLUMNS,
            'c.event_date = {d:Date}'
            .($shortOnly ? ' AND c.is_short = 1' : '')
            .($exactOnly ? ' AND c.exact_tld_count >= 2' : ''),
            'ORDER BY c.score DESC, c.name LIMIT {lim:UInt32}',
        );

        $rows = $this->clickhouse->rows($sql, [
            'd' => $date,
            'from' => $date,
            'event' => 'added',
            'hidden' => $this->hiddenNames(),
            'lim' => $limit,
        ]);

        return $this->decorate($rows);
    }

    /**
     * A page of candidates for a date, optionally narrowed by name keywords.
     *
     * @return array{candidates: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function pageForDate(
        string $date,
        string $term = '',
        int $perPage = 25,
        int $page = 1,
        bool $shortOnly = false,
        bool $exactOnly = false,
        ?int $minVariations = null,
        ?int $maxVariations = null,
        ?int $exactVariations = null,
    ): array {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $params = [
            'd' => $date,
            'from' => $date,
            'event' => 'added',
            'hidden' => $this->hiddenNames(),
        ];

        $where = 'c.event_date = {d:Date}'
            .($shortOnly ? ' AND c.is_short = 1' : '')
            .($exactOnly ? ' AND c.exact_tld_count >= 2' : '');

        if ($exactVariations !== null) {
            $where .= ' AND c.weaker_count = {exactv:UInt32}';
            $params['exactv'] = $exactVariations;
        } else {
            if ($minVariations !== null) {
                $where .= ' AND c.weaker_count >= {minv:UInt32}';
                $params['minv'] = $minVariations;
            }

            if ($maxVariations !== null) {
                $where .= ' AND c.weaker_count <= {maxv:UInt32}';
                $params['maxv'] = $maxVariations;
            }
        }

        foreach ($this->searchTerms($term) as $index => $part) {
            $where .= ' AND positionCaseInsensitive(c.name, {term'.$index.':String}) > 0';
            $params['term'.$index] = $part;
        }

        $totalRows = $this->clickhouse->rows(
            $this->visibleCandidatesSql('SELECT count() AS total', $where, ''),
            $params,
        );

        $total = (int) ($totalRows[0]['total'] ?? 0);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $rows = $this->clickhouse->rows(
            $this->visibleCandidatesSql(
                'SELECT '.self::CANDIDATE_COLUMNS,
                $where,
                'ORDER BY c.score DESC, c.name LIMIT {lim:UInt32} OFFSET {off:UInt32}',
            ),
            $params + ['lim' => $perPage, 'off' => ($page - 1) * $perPage],
        );

        return [
            'candidates' => $this->decorate($rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /**
     * Split a keyword query into searchable name fragments. A full domain is
     * reduced to its second level label so "acme.com" also matches "acme".
     *
     * @return array<int, string>
     */
    private function searchTerms(string $term): array
    {
        $terms = preg_split('/\s+/', strtolower(trim($term))) ?: [];

        $terms = array_map(
            fn (string $part) => str_contains($part, '.')
                ? (string) preg_replace('/\.[^.]+$/', '', $part)
                : $part,
            $terms,
        );

        return array_slice(
            array_values(array_filter($terms, fn (string $part) => $part !== '')),
            0,
            5,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forRange(string $from, string $to, int $limit = 1000): array
    {
        $rows = $this->clickhouse->rows(
            $this->visibleCandidatesSql(
                'SELECT '.self::CANDIDATE_COLUMNS,
                'c.event_date >= {from:Date} AND c.event_date <= {to:Date}',
                'ORDER BY c.event_date DESC, c.score DESC, c.name LIMIT {lim:UInt32}',
            ),
            ['from' => $from, 'to' => $to, 'event' => 'added', 'hidden' => $this->hiddenNames(), 'lim' => $limit],
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

        $from = now()->subDays(self::SEARCH_LOOKBACK_DAYS)->format('Y-m-d');

        $rows = $this->clickhouse->rows(
            $this->visibleCandidatesSql(
                'SELECT '.self::CANDIDATE_COLUMNS,
                'c.event_date >= {from:Date} '
                .'AND (c.name = {exact:String} OR positionCaseInsensitive(c.name, {partial:String}) > 0)',
                'ORDER BY c.event_date DESC, c.score DESC, c.name LIMIT {lim:UInt32}',
            ),
            [
                'from' => $from,
                'exact' => $term,
                'partial' => $term,
                'event' => 'added',
                'hidden' => $this->hiddenNames(),
                'lim' => $limit,
            ],
        );

        return $this->decorate($rows);
    }

    /**
     * Full domains that dropped on a given day.
     *
     * @return array<int, string>
     */
    public function removedNames(string $date, int $limit = 2000): array
    {
        if ($this->pendingDeleteReady()) {
            $rows = $this->clickhouse->rows(
                sprintf(
                    'SELECT DISTINCT name, tld FROM %s.pendingdelete_names FINAL '
                    .'WHERE status = {status:String} AND toDate(drop_utc) = {d:Date} '
                    .'ORDER BY name, tld LIMIT {lim:UInt32}',
                    $this->database,
                ),
                ['status' => 'dropped', 'd' => $date, 'lim' => $limit],
            );

            return $this->domains($rows);
        }

        $rows = $this->clickhouse->rows(
            sprintf(
                'SELECT name FROM %s.zone_events '
                .'WHERE tld = {tld:String} AND event = {event:String} AND event_date = {d:Date} '
                .'ORDER BY name LIMIT {lim:UInt32}',
                $this->database,
            ),
            ['tld' => 'com', 'event' => 'removed', 'd' => $date, 'lim' => $limit],
        );

        return $this->domains($rows, 'com');
    }

    /**
     * Full domains currently in redemption or pending delete.
     *
     * @return array<int, string>
     */
    public function addedNames(string $date, int $limit = 2000): array
    {
        if ($this->pendingDeleteReady()) {
            $rows = $this->clickhouse->rows(
                sprintf(
                    'SELECT name, tld FROM %s.pendingdelete_names FINAL '
                    ."WHERE status IN ('pending_delete', 'redemption') "
                    .'ORDER BY name, tld LIMIT {lim:UInt32}',
                    $this->database,
                ),
                ['lim' => $limit],
            );

            return $this->domains($rows);
        }

        $rows = $this->clickhouse->rows(
            sprintf(
                'SELECT name FROM %s.zone_events '
                .'WHERE tld = {tld:String} AND event = {event:String} AND event_date = {d:Date} '
                .'ORDER BY name LIMIT {lim:UInt32}',
                $this->database,
            ),
            ['tld' => 'com', 'event' => 'added', 'd' => $date, 'lim' => $limit],
        );

        return $this->domains($rows, 'com');
    }

    /**
     * How many domains dropped between two dates.
     */
    public function removedCount(string $from, string $to): int
    {
        [$table, $where] = $this->pendingDeleteReady()
            ? [
                $this->database.'.pendingdelete_names FINAL',
                'status = {status:String} AND toDate(drop_utc) >= {from:Date} AND toDate(drop_utc) <= {to:Date}',
            ]
            : [
                $this->database.'.zone_events',
                'tld = {tld:String} AND event = {event:String} '
                .'AND event_date >= {from:Date} AND event_date <= {to:Date}',
            ];

        $rows = $this->clickhouse->rows(
            sprintf('SELECT count() AS total FROM %s WHERE %s', $table, $where),
            ['status' => 'dropped', 'tld' => 'com', 'event' => 'removed', 'from' => $from, 'to' => $to],
        );

        return (int) ($rows[0]['total'] ?? 0);
    }

    /**
     * @return array<string, int>
     */
    public function countsForDate(string $date): array
    {
        $counts = [
            'removed' => 0,
            'added' => 0,
            'candidates' => 0,
            'dropped' => 0,
            'pending' => 0,
        ];

        if ($this->pendingDeleteReady()) {
            $rows = $this->clickhouse->rows(
                sprintf(
                    "SELECT countIf(status = 'dropped' AND toDate(drop_utc) = {d:Date}) AS dropped, "
                    ."countIf(status IN ('pending_delete', 'redemption')) AS pending "
                    .'FROM %s.pendingdelete_names FINAL',
                    $this->database,
                ),
                ['d' => $date],
            );

            $counts['dropped'] = (int) ($rows[0]['dropped'] ?? 0);
            $counts['pending'] = (int) ($rows[0]['pending'] ?? 0);

            // removed/added remain for existing callers; the daily dataset has
            // no zone diff, so "added" now means still in the pending backlog.
            $counts['removed'] = $counts['dropped'];
            $counts['added'] = $counts['pending'];
        } else {
            $rows = $this->clickhouse->rows(
                sprintf(
                    'SELECT event, count() AS total FROM %s.zone_events '
                    .'WHERE tld = {tld:String} AND event_date = {d:Date} GROUP BY event',
                    $this->database,
                ),
                ['tld' => 'com', 'd' => $date],
            );

            foreach ($rows as $row) {
                $counts[(string) ($row['event'] ?? '')] = (int) ($row['total'] ?? 0);
            }
        }

        $candidateRows = $this->clickhouse->rows(
            $this->visibleCandidatesSql(
                'SELECT count() AS total',
                'c.event_date = {d:Date}',
                '',
            ),
            ['d' => $date, 'from' => $date, 'event' => 'added', 'hidden' => $this->hiddenNames()],
        );

        $counts['candidates'] = (int) ($candidateRows[0]['total'] ?? 0);

        return $counts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function domains(array $rows, string $defaultTld = ''): array
    {
        $domains = [];

        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            $tld = (string) ($row['tld'] ?? $defaultTld);

            if ($name !== '' && $tld !== '') {
                $domains[] = $name.'.'.$tld;
            }
        }

        return $domains;
    }

    /**
     * Whether the pending-delete import has produced any rows. Until the first
     * import the dashboard falls back to the zone diff history.
     */
    private function pendingDeleteReady(): bool
    {
        if ($this->pendingDeleteReady === null) {
            $rows = $this->clickhouse->rows(sprintf(
                'SELECT count() AS total FROM %s.pendingdelete_names',
                $this->database,
            ));

            $this->pendingDeleteReady = ((int) ($rows[0]['total'] ?? 0)) > 0;
        }

        return $this->pendingDeleteReady;
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
     * A candidate disappears once the same domain is seen again as registered:
     * either in the current pending-delete dataset or in the historical zone
     * diff. The join runs in ClickHouse so the limit applies to visible rows.
     */
    private function visibleCandidatesSql(string $select, string $where, string $tail): string
    {
        return $select
            .' FROM '.$this->database.'.com_candidates AS c '
            .'LEFT JOIN ('
            .'SELECT tld, name, max(seen_on) AS seen_on FROM ('
            .'SELECT tld, name, toDate(observed_at) AS seen_on FROM '.$this->database.'.pendingdelete_names FINAL '
            ."WHERE status != 'dropped' "
            .'UNION ALL '
            .'SELECT tld, name, max(event_date) AS seen_on FROM '.$this->database.'.zone_events '
            .'WHERE event = {event:String} AND event_date >= {from:Date} GROUP BY tld, name'
            .') GROUP BY tld, name'
            .') AS restored ON restored.tld = c.tld AND restored.name = c.name '
            .'WHERE '.$where
            .' AND (restored.seen_on IS NULL OR restored.seen_on < c.event_date) '
            ."AND NOT (c.tld = 'com' AND c.name IN {hidden:Array(String)}) "
            .$tail
            .' SETTINGS join_use_nulls = 1';
    }

    /**
     * @return array<int, string>
     */
    private function hiddenNames(): array
    {
        if ($this->hiddenNames === null) {
            $names = RdapStatus::hiddenNames();
            $this->hiddenNames = $names === [] ? ['__dropwatch_no_hidden_names__'] : $names;
        }

        return $this->hiddenNames;
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

        $domains = array_map(fn (array $row) => $this->domainOf($row), $rows);

        $watched = Watchlist::whereIn('domain', $domains)->pluck('domain')->flip();

        $graceDays = (int) config('zones.drop.redemption_days', 30)
            + (int) config('zones.drop.pending_delete_days', 5);

        foreach ($rows as $index => $row) {
            $domain = $this->domainOf($row);
            $eventDate = (string) ($row['event_date'] ?? '');
            $dropDate = (string) ($row['drop_date'] ?? '');

            $rows[$index]['domain'] = $domain;
            $rows[$index]['tld'] = (string) ($row['tld'] ?? '') ?: 'com';
            $rows[$index]['status'] = (string) ($row['status'] ?? '');
            $rows[$index]['weaker_found'] = array_values((array) ($row['weaker_found'] ?? []));
            $rows[$index]['weaker_types'] = array_values((array) ($row['weaker_types'] ?? []));
            $rows[$index]['is_short'] = (bool) ($row['is_short'] ?? false);
            $rows[$index]['exact_tld_count'] = (int) ($row['exact_tld_count'] ?? 0);
            $rows[$index]['estimated_drop_date'] = $dropDate !== ''
                ? $dropDate
                : ($eventDate === '' ? null : Carbon::parse($eventDate)->addDays($graceDays)->format('Y-m-d'));
            $rows[$index]['watchlisted'] = $watched->has($domain);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function domainOf(array $row): string
    {
        $name = (string) ($row['name'] ?? '');
        $tld = (string) ($row['tld'] ?? '') ?: 'com';

        return $name === '' ? '' : $name.'.'.$tld;
    }
}
