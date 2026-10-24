<?php

namespace App\Services\Zone;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use App\Support\LookupLink;
use Illuminate\Support\Carbon;

/**
 * Searches the latest zone snapshot across every TLD.
 *
 * All user input is passed as ClickHouse query parameters. The modes use
 * equality, startsWith and position(), never LIKE, so % and _ are literal
 * characters and can never act as wildcards.
 */
class ZoneSearch
{
    public const MODES = ['contains', 'starts', 'ends', 'exact'];

    public const PER_PAGE = 100;

    public const MAX_RESULTS = 5000;

    public const MAX_PAGES = self::MAX_RESULTS / self::PER_PAGE;

    public const CONTAINS_MIN_LENGTH = 3;

    public const TIMEOUT_SECONDS = 20;

    public const EVENT_WINDOW_DAYS = 45;

    private const TOO_BROAD = 'The search is too broad. Add a TLD, more characters or a length filter.';

    public function __construct(
        private readonly ClickHouseClient $clickhouse,
        private readonly string $database = 'zones',
    ) {}

    public function latestSnapshot(): ?string
    {
        $row = $this->clickhouse->row(
            sprintf('SELECT count() AS total, max(snapshot_date) AS latest FROM %s.zone_names', $this->database),
        );

        if ($row === null || (int) ($row['total'] ?? 0) === 0) {
            return null;
        }

        return (string) ($row['latest'] ?? '');
    }

    /**
     * @return array<int, string>
     */
    public function tlds(?string $snapshotDate): array
    {
        if ($snapshotDate === null || $snapshotDate === '') {
            return [];
        }

        $rows = $this->clickhouse->rows(
            sprintf(
                'SELECT DISTINCT tld FROM %s.zone_names WHERE snapshot_date = {d:Date} ORDER BY tld',
                $this->database,
            ),
            ['d' => $snapshotDate],
        );

        return array_values(array_filter(array_map(
            fn (array $row) => (string) ($row['tld'] ?? ''),
            $rows,
        )));
    }

    /**
     * @param  array{query:string, mode:string, tlds:array<int,string>, min_length:?int, max_length:?int, hide_digits:bool, hide_hyphens:bool, page:int}  $filters
     * @return array{
     *     results: array<int, array{domain:string, tld:string, name:string, removed_on:?string, added_on:?string, lookup_url:string}>,
     *     page: int,
     *     per_page: int,
     *     has_more: bool,
     *     error: ?string,
     *     latest: ?string
     * }
     */
    public function search(array $filters): array
    {
        $query = strtolower(trim((string) ($filters['query'] ?? '')));
        $mode = in_array($filters['mode'] ?? '', self::MODES, true) ? (string) $filters['mode'] : 'contains';
        $page = min(max(1, (int) ($filters['page'] ?? 1)), self::MAX_PAGES);
        $minLength = $filters['min_length'] ?? null;
        $maxLength = $filters['max_length'] ?? null;

        $latest = $this->latestSnapshot();

        if ($query === '') {
            return $this->emptyResult($page, $latest);
        }

        if ($mode === 'contains' && strlen($query) < self::CONTAINS_MIN_LENGTH) {
            return $this->errorResult('Contains needs at least '.self::CONTAINS_MIN_LENGTH.' characters.', $page, $latest);
        }

        if ($minLength !== null && $maxLength !== null && $minLength > $maxLength) {
            return $this->errorResult('The minimum length cannot be greater than the maximum length.', $page, $latest);
        }

        if ($latest === null) {
            return $this->emptyResult($page, null);
        }

        $conditions = ['snapshot_date = {d:Date}'];
        $params = ['d' => $latest];

        switch ($mode) {
            case 'starts':
                $conditions[] = 'startsWith(name, {q:String})';
                $params['q'] = $query;
                break;

            case 'ends':
                $conditions[] = 'startsWith(name_rev, {rev:String})';
                $params['rev'] = strrev($query);
                break;

            case 'exact':
                $conditions[] = 'name = {q:String}';
                $params['q'] = $query;
                break;

            default:
                $conditions[] = 'positionCaseInsensitive(name, {q:String}) > 0';
                $params['q'] = $query;
                break;
        }

        $tlds = $this->cleanTlds($filters['tlds'] ?? []);

        if ($tlds !== []) {
            $conditions[] = 'tld IN {tlds:Array(String)}';
            $params['tlds'] = $tlds;
        }

        if ($minLength !== null) {
            $conditions[] = 'length(name) >= {min:UInt8}';
            $params['min'] = $minLength;
        }

        if ($maxLength !== null) {
            $conditions[] = 'length(name) <= {max:UInt8}';
            $params['max'] = $maxLength;
        }

        if ($filters['hide_digits'] ?? false) {
            $conditions[] = "match(name, '[0-9]') = 0";
        }

        if ($filters['hide_hyphens'] ?? false) {
            $conditions[] = "position(name, '-') = 0";
        }

        $params['limit'] = self::PER_PAGE + 1;
        $params['offset'] = ($page - 1) * self::PER_PAGE;

        $settings = 'max_execution_time = '.self::TIMEOUT_SECONDS;

        if ($mode === 'ends') {
            // Suffix searches must use the name_rev_order projection; fail
            // loudly if it is missing instead of scanning every snapshot row.
            $settings .= ', force_optimize_projection = 1';
        }

        $sql = sprintf(
            'SELECT tld, name FROM %s.zone_names WHERE %s ORDER BY name, tld '
            .'LIMIT {limit:UInt32} OFFSET {offset:UInt32} SETTINGS %s',
            $this->database,
            implode(' AND ', $conditions),
            $settings,
        );

        try {
            $rows = $this->clickhouse->rows($sql, $params);
        } catch (ClickHouseException $exception) {
            if ($this->isTooBroad($exception)) {
                return $this->errorResult(self::TOO_BROAD, $page, $latest);
            }

            throw $exception;
        }

        $hasMore = count($rows) > self::PER_PAGE && $page < self::MAX_PAGES;
        $rows = array_slice($rows, 0, self::PER_PAGE);

        $events = $this->eventsFor($rows);

        $results = [];

        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            $tld = (string) ($row['tld'] ?? '');

            if ($name === '' || $tld === '') {
                continue;
            }

            $domain = $name.'.'.$tld;
            $key = $tld."\0".$name;
            $status = $events[$key] ?? [];

            $results[] = [
                'domain' => $domain,
                'tld' => $tld,
                'name' => $name,
                'removed_on' => $status['removed'] ?? null,
                'added_on' => $status['added'] ?? null,
                'lookup_url' => LookupLink::for($domain),
            ];
        }

        return [
            'results' => $results,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'has_more' => $hasMore,
            'error' => null,
            'latest' => $latest,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array{removed?:string, added?:string}>
     */
    private function eventsFor(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $names = [];
        $tlds = [];

        foreach ($rows as $row) {
            $names[] = (string) ($row['name'] ?? '');
            $tlds[] = (string) ($row['tld'] ?? '');
        }

        $from = Carbon::now()->subDays(self::EVENT_WINDOW_DAYS)->format('Y-m-d');

        $events = $this->clickhouse->rows(
            sprintf(
                'SELECT tld, name, event, max(event_date) AS last_event_date FROM %s.zone_events '
                .'WHERE event_date >= {from:Date} AND name IN {names:Array(String)} AND tld IN {tlds:Array(String)} '
                .'GROUP BY tld, name, event',
                $this->database,
            ),
            [
                'from' => $from,
                'names' => array_values(array_unique($names)),
                'tlds' => array_values(array_unique($tlds)),
            ],
        );

        $status = [];

        foreach ($events as $event) {
            $eventName = (string) ($event['event'] ?? '');
            $key = (string) ($event['tld'] ?? '')."\0".(string) ($event['name'] ?? '');

            if ($eventName !== 'removed' && $eventName !== 'added') {
                continue;
            }

            $status[$key][$eventName] = (string) ($event['last_event_date'] ?? '');
        }

        return $status;
    }

    /**
     * @return array<int, string>
     */
    private function cleanTlds(mixed $tlds): array
    {
        if (! is_array($tlds)) {
            return [];
        }

        $clean = [];

        foreach ($tlds as $tld) {
            $tld = strtolower(trim((string) $tld));

            if ($tld !== '' && preg_match('/^[a-z0-9-]{2,63}$/', $tld) === 1) {
                $clean[$tld] = true;
            }
        }

        return array_keys($clean);
    }

    private function isTooBroad(ClickHouseException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'max_execution_time');
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, page:int, per_page:int, has_more:bool, error:?string, latest:?string}
     */
    private function emptyResult(int $page, ?string $latest): array
    {
        return [
            'results' => [],
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'has_more' => false,
            'error' => null,
            'latest' => $latest,
        ];
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, page:int, per_page:int, has_more:bool, error:?string, latest:?string}
     */
    private function errorResult(string $message, int $page, ?string $latest): array
    {
        return [
            'results' => [],
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'has_more' => false,
            'error' => $message,
            'latest' => $latest,
        ];
    }
}
