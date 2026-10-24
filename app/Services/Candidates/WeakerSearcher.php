<?php

namespace App\Services\Candidates;

use App\Services\ClickHouse\ClickHouseClient;
use Illuminate\Support\Facades\Log;

/**
 * Finds the weaker versions of removed .com names.
 *
 * A weaker version is a registered domain built around the same name on
 * another TLD, with a hyphen inserted, that starts with, ends with or is a
 * plural of the name.
 *
 * Queries are batched: one query per rule for a whole chunk of removed names,
 * never one query per name. Every snapshot is searched, including .com; only
 * the dropped domain itself is excluded.
 *
 * Misspellings and contains-in-the-middle are phase 2: the hooks exist but
 * stay disabled by default.
 */
class WeakerSearcher
{
    public const SIGNAL_OTHER_TLD = 'other_tld';

    public const SIGNAL_HYPHEN = 'hyphen';

    public const SIGNAL_STARTS_WITH = 'starts_with';

    public const SIGNAL_ENDS_WITH = 'ends_with';

    public const SIGNAL_PLURAL = 'plural';

    public const SIGNAL_MISSPELLING = 'misspelling';

    public const SIGNAL_CONTAINS = 'contains';

    /**
     * Names of this length or shorter use the restricted rule set: the
     * starts_with and ends_with rules may only add one or two characters and
     * only a limited number of weaker versions is kept.
     */
    public const SHORT_NAME_MAX_LENGTH = 4;

    /** How many weaker versions a short name keeps. */
    public const SHORT_MAX_WEAKER = 20;

    /** How many characters starts_with/ends_with may add for a short name. */
    public const SHORT_MAX_ADDED_CHARS = 2;

    /** The safety cap for one name; the per-rule limits normally keep us far below it. */
    private const MAX_DETAILS_PER_NAME = 500;

    /** Batched SQL can be a few megabytes, above ClickHouse's 256 KiB default. */
    private const QUERY_SETTINGS = ['max_query_size' => 67108864];

    /** Suffix queries must use the projection or not run at all. */
    private const SUFFIX_SETTINGS = [
        'max_query_size' => 67108864,
        'force_optimize_projection' => 1,
    ];

    /** Safety cap for one batched prefix/suffix lookup. */
    private const MAX_RANGE_ROWS = 200000;

    /** False once the name_rev_order projection is known to be missing. */
    private ?bool $endsWithAvailable = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly ClickHouseClient $clickhouse,
        private readonly array $config,
        private readonly string $database = 'zones',
    ) {}

    /**
     * Find the weaker versions of a chunk of names.
     *
     * @param  array<int, string>  $names
     * @param  array<int, string>  $tlds  TLDs that have a snapshot for the given date
     * @return array<string, array{
     *     weaker_found: array<int, string>,
     *     weaker_types: array<int, string>,
     *     details: array<string, array{domain:string, tld:string, name:string, signals:array<int,string>, added_chars:int, nameservers:array<int,string>}>,
     *     nameservers: array<string, array<int, string>>,
     *     exact_tld_count: int,
     *     same_nameservers: bool
     * }>
     */
    public function findMany(array $names, string $snapshotDate, array $tlds): array
    {
        $names = array_values(array_unique(array_filter(
            array_map(fn ($name) => strtolower(trim((string) $name)), $names),
            fn (string $name) => $name !== '',
        )));

        $tlds = array_values(array_unique(array_filter(
            array_map(fn ($tld) => strtolower(trim((string) $tld)), $tlds),
            fn (string $tld) => $tld !== '',
        )));

        if ($names === [] || $tlds === []) {
            return [];
        }

        $limit = max(1, (int) ($this->config['max_per_rule'] ?? 50));
        $details = [];

        // 1. The same name on another TLD.
        $this->collect(
            $details,
            $this->queryExact($names, $snapshotDate, $tlds),
            self::SIGNAL_OTHER_TLD,
            fn (string $base, string $label): int => 0,
            $limit,
        );

        // 2. The same name with a hyphen inserted at any split point.
        $hyphens = [];

        foreach ($names as $name) {
            foreach ($this->hyphenVariants($name) as $variant) {
                $hyphens[$variant][$name] = true;
            }
        }

        $this->collectVariants(
            $details,
            $hyphens,
            $snapshotDate,
            $tlds,
            $limit,
            self::SIGNAL_HYPHEN,
            fn (string $base, string $label): int => 1,
        );

        // 3. Names that start with the dropped name.
        $this->collectPrefixes(
            $details,
            $names,
            $this->queryPrefixes($names, $snapshotDate, $tlds),
            self::SIGNAL_STARTS_WITH,
            fn (string $base, string $label): int => max(1, strlen($label) - strlen($base)),
            $limit,
        );

        // 4. Names that end with the dropped name (searched through name_rev).
        if ($this->endsWithAvailable()) {
            $this->collectSuffixes(
                $details,
                $names,
                $this->querySuffixes($names, $snapshotDate, $tlds),
                self::SIGNAL_ENDS_WITH,
                fn (string $base, string $label): int => max(1, strlen($label) - strlen($base)),
                $limit,
            );
        }

        // 5. Plural or singular of the dropped name.
        $plurals = [];

        foreach ($names as $name) {
            foreach ($this->pluralVariants($name) as $variant) {
                $plurals[$variant][$name] = true;
            }
        }

        $this->collectVariants(
            $details,
            $plurals,
            $snapshotDate,
            $tlds,
            $limit,
            self::SIGNAL_PLURAL,
            fn (string $base, string $label): int => abs(strlen($label) - strlen($base)),
        );

        // Phase 2 hooks - disabled unless explicitly enabled.
        if ($this->config['phase2_enabled'] ?? false) {
            $misspellings = [];

            foreach ($names as $name) {
                foreach ($this->misspellingVariants($name) as $variant) {
                    $misspellings[$variant][$name] = true;
                }
            }

            $this->collectVariants(
                $details,
                $misspellings,
                $snapshotDate,
                $tlds,
                $limit,
                self::SIGNAL_MISSPELLING,
                fn (string $base, string $label): int => 0,
            );

            $this->collect(
                $details,
                $this->queryContains($names, $snapshotDate, $tlds, $limit),
                self::SIGNAL_CONTAINS,
                fn (string $base, string $label): int => max(1, strlen($label) - strlen($base)),
                $limit,
            );
        }

        return $this->assemble($names, $details, $this->exactTldStats($names, $snapshotDate));
    }

    /**
     * Find every weaker version of a single name.
     *
     * @param  array<int, string>  $tlds
     * @return array{
     *     weaker_found: array<int, string>,
     *     weaker_types: array<int, string>,
     *     details: array<string, array{domain:string, tld:string, name:string, signals:array<int,string>, added_chars:int, nameservers:array<int,string>}>,
     *     nameservers: array<string, array<int, string>>,
     *     exact_tld_count: int,
     *     same_nameservers: bool
     * }
     */
    public function find(string $name, string $snapshotDate, array $tlds): array
    {
        $name = strtolower(trim($name));

        return $this->findMany([$name], $snapshotDate, $tlds)[$name] ?? $this->emptyResult();
    }

    /**
     * @param  array<string, array<string, array{domain:string, tld:string, name:string, signals:array<int,string>, added_chars:int, nameservers:array<int,string>}>>  $details
     * @param  array<int, string>  $names
     * @param  array<string, array{count:int, same_nameservers:bool}>  $exactStats
     * @return array<string, array{
     *     weaker_found: array<int, string>,
     *     weaker_types: array<int, string>,
     *     details: array<string, array{domain:string, tld:string, name:string, signals:array<int,string>, added_chars:int, nameservers:array<int,string>}>,
     *     nameservers: array<string, array<int, string>>,
     *     exact_tld_count: int,
     *     same_nameservers: bool
     * }>
     */
    private function assemble(array $names, array $details, array $exactStats): array
    {
        $results = [];

        foreach ($names as $name) {
            $found = $details[$name] ?? [];
            $exact = $exactStats[$name] ?? ['count' => 0, 'same_nameservers' => false];

            if (strlen($name) <= self::SHORT_NAME_MAX_LENGTH) {
                // The unrestricted rules are kept first, then the closest
                // prefix and suffix matches, when a short name matches more
                // versions than we are willing to store.
                $rank = fn (array $detail): int => array_intersect(
                    $detail['signals'],
                    [self::SIGNAL_OTHER_TLD, self::SIGNAL_HYPHEN, self::SIGNAL_PLURAL],
                ) === [] ? 1 : 0;

                uasort($found, fn (array $a, array $b) => [$rank($a), $a['added_chars'], $a['domain']] <=> [$rank($b), $b['added_chars'], $b['domain']]);
                $found = array_slice($found, 0, self::SHORT_MAX_WEAKER, true);
            } else {
                ksort($found);
            }

            $weakerFound = [];
            $weakerTypes = [];
            $nameservers = [];

            foreach ($found as $domain => $detail) {
                $weakerFound[] = $domain;
                $weakerTypes[] = implode(',', $detail['signals']);
                $nameservers[$domain] = $detail['nameservers'];
            }

            $results[$name] = [
                'weaker_found' => $weakerFound,
                'weaker_types' => $weakerTypes,
                'details' => $found,
                'nameservers' => $nameservers,
                'exact_tld_count' => $exact['count'],
                'same_nameservers' => $exact['same_nameservers'],
            ];
        }

        return $results;
    }

    /**
     * @return array{weaker_found: array<int, string>, weaker_types: array<int, string>, details: array<string, array<string, mixed>>, nameservers: array<string, array<int, string>>, exact_tld_count: int, same_nameservers: bool}
     */
    private function emptyResult(): array
    {
        return [
            'weaker_found' => [],
            'weaker_types' => [],
            'details' => [],
            'nameservers' => [],
            'exact_tld_count' => 0,
            'same_nameservers' => false,
        ];
    }

    /**
     * @param  array<string, array<string, array{domain:string, tld:string, name:string, signals:array<int,string>, added_chars:int, nameservers:array<int,string>}>>  $details
     * @param  array<int, array<string, mixed>>  $rows
     * @param  callable(string, string): int  $addedChars
     */
    private function collect(array &$details, array $rows, string $signal, callable $addedChars, int $limit): void
    {
        $counts = [];

        foreach ($rows as $row) {
            $base = strtolower((string) ($row['base'] ?? ''));

            if ($base === '' || ($counts[$base] ?? 0) >= $limit) {
                continue;
            }

            $counts[$base] = ($counts[$base] ?? 0) + 1;

            $this->addDetail($details, $base, $row, $signal, $addedChars);
        }
    }

    /**
     * @param  array<string, array<string, array{domain:string, tld:string, name:string, signals:array<int,string>, added_chars:int, nameservers:array<int,string>}>>  $details
     * @param  array<string, mixed>  $row
     * @param  callable(string, string): int  $addedChars
     */
    private function addDetail(array &$details, string $base, array $row, string $signal, callable $addedChars): void
    {
        $tld = strtolower((string) ($row['tld'] ?? ''));
        $label = strtolower((string) ($row['name'] ?? ''));

        if ($tld === '' || $label === '') {
            return;
        }

        $domain = $label.'.'.$tld;

        if (! isset($details[$base][$domain])
            && isset($details[$base])
            && count($details[$base]) >= self::MAX_DETAILS_PER_NAME) {
            return;
        }

        if (! isset($details[$base][$domain])) {
            $details[$base][$domain] = [
                'domain' => $domain,
                'tld' => $tld,
                'name' => $label,
                'signals' => [],
                'added_chars' => PHP_INT_MAX,
                'nameservers' => [],
            ];
        }

        $ns = array_values(array_filter(array_map(
            'trim',
            preg_split('/[,\s]+/', (string) ($row['ns'] ?? '')) ?: [],
        )));

        $details[$base][$domain]['nameservers'] = array_values(array_unique(array_merge(
            $details[$base][$domain]['nameservers'],
            $ns,
        )));

        if (! in_array($signal, $details[$base][$domain]['signals'], true)) {
            $details[$base][$domain]['signals'][] = $signal;
        }

        $added = max(0, $addedChars($base, $label));

        if ($added < $details[$base][$domain]['added_chars']) {
            $details[$base][$domain]['added_chars'] = $added;
        }
    }

    /**
     * Resolve generated variants (hyphen, plural, misspelling) with one
     * indexed lookup and map each row back to every name that produced it.
     *
     * @param  array<string, array<string, true>>  $variants  variant => base => true
     * @param  array<int, string>  $tlds
     * @param  callable(string, string): int  $addedChars
     */
    private function collectVariants(
        array &$details,
        array $variants,
        string $snapshotDate,
        array $tlds,
        int $limit,
        string $signal,
        callable $addedChars,
    ): void {
        if ($variants === []) {
            return;
        }

        $counts = [];

        foreach ($this->queryNames(array_map('strval', array_keys($variants)), $snapshotDate, $tlds) as $row) {
            $variant = strtolower((string) ($row['name'] ?? ''));

            foreach (array_keys($variants[$variant] ?? []) as $base) {
                $base = (string) $base;

                if (($counts[$base] ?? 0) >= $limit) {
                    continue;
                }

                $counts[$base] = ($counts[$base] ?? 0) + 1;
                $row['base'] = $base;

                $this->addDetail($details, $base, $row, $signal, $addedChars);
            }
        }
    }

    /**
     * Map prefix matches back to the dropped names. The SQL has already
     * limited the rows to the prefix ranges; this only applies the short-name
     * rule and the per-rule cap.
     *
     * @param  array<int, string>  $names
     * @param  array<int, array<string, mixed>>  $rows
     * @param  callable(string, string): int  $addedChars
     */
    private function collectPrefixes(
        array &$details,
        array $names,
        array $rows,
        string $signal,
        callable $addedChars,
        int $limit,
    ): void {
        if ($rows === [] || $names === []) {
            return;
        }

        $byFirst = [];

        foreach ($names as $name) {
            $byFirst[$name[0]][] = $name;
        }

        $counts = [];

        foreach ($rows as $row) {
            $label = strtolower((string) ($row['name'] ?? ''));

            if ($label === '') {
                continue;
            }

            foreach ($byFirst[$label[0]] ?? [] as $base) {
                if (! str_starts_with($label, $base) || $label === $base) {
                    continue;
                }

                $added = strlen($label) - strlen($base);

                if (strlen($base) <= self::SHORT_NAME_MAX_LENGTH
                    && ($added < 1 || $added > self::SHORT_MAX_ADDED_CHARS)) {
                    continue;
                }

                if (($counts[$base] ?? 0) >= $limit) {
                    continue;
                }

                $counts[$base] = ($counts[$base] ?? 0) + 1;
                $row['base'] = $base;

                $this->addDetail($details, $base, $row, $signal, $addedChars);
            }
        }
    }

    /**
     * Map suffix matches back to the dropped names.
     *
     * @param  array<int, string>  $names
     * @param  array<int, array<string, mixed>>  $rows
     * @param  callable(string, string): int  $addedChars
     */
    private function collectSuffixes(
        array &$details,
        array $names,
        array $rows,
        string $signal,
        callable $addedChars,
        int $limit,
    ): void {
        if ($rows === [] || $names === []) {
            return;
        }

        $byLast = [];

        foreach ($names as $name) {
            $byLast[substr($name, -1)][] = $name;
        }

        $counts = [];

        foreach ($rows as $row) {
            $label = strtolower((string) ($row['name'] ?? ''));

            if ($label === '') {
                continue;
            }

            foreach ($byLast[substr($label, -1)] ?? [] as $base) {
                if (! str_ends_with($label, $base) || $label === $base) {
                    continue;
                }

                $added = strlen($label) - strlen($base);

                if (strlen($base) <= self::SHORT_NAME_MAX_LENGTH
                    && ($added < 1 || $added > self::SHORT_MAX_ADDED_CHARS)) {
                    continue;
                }

                if (($counts[$base] ?? 0) >= $limit) {
                    continue;
                }

                $counts[$base] = ($counts[$base] ?? 0) + 1;
                $row['base'] = $base;

                $this->addDetail($details, $base, $row, $signal, $addedChars);
            }
        }
    }

    /**
     * The ends-with search needs the name_rev_order projection; without it
     * every query would scan the whole snapshot. Check once per process and
     * skip the rule until the projection is materialized.
     */
    private function endsWithAvailable(): bool
    {
        if ($this->endsWithAvailable !== null) {
            return $this->endsWithAvailable;
        }

        $count = (int) $this->clickhouse->scalar(sprintf(
            'SELECT count() FROM system.projection_parts '
            ."WHERE database = %s AND table = 'zone_names' AND name = 'name_rev_order'",
            $this->sqlString($this->database),
        ));

        if ($count === 0) {
            Log::warning('WeakerSearcher: ends-with rule skipped; the name_rev_order projection is not materialized.');
        }

        return $this->endsWithAvailable = $count > 0;
    }

    /**
     * Every split point of the name with a hyphen inserted.
     *
     * @return array<int, string>
     */
    public function hyphenVariants(string $name): array
    {
        $variants = [];
        $length = strlen($name);

        for ($split = 1; $split < $length; $split++) {
            $left = substr($name, 0, $split);
            $right = substr($name, $split);

            if ($left === '' || $right === '') {
                continue;
            }

            if (str_ends_with($left, '-') || str_starts_with($right, '-')) {
                continue;
            }

            $variants[] = $left.'-'.$right;
        }

        return array_values(array_unique($variants));
    }

    /**
     * @return array<int, string>
     */
    public function pluralVariants(string $name): array
    {
        $variants = [];

        if (str_ends_with($name, 's')) {
            $variants[] = substr($name, 0, -1);
        } else {
            $variants[] = $name.'s';
        }

        return array_values(array_unique(array_filter($variants)));
    }

    /**
     * One deleted, doubled, swapped or substituted letter.
     *
     * @return array<int, string>
     */
    public function misspellingVariants(string $name): array
    {
        $variants = [];
        $length = strlen($name);

        // deleted
        for ($i = 0; $i < $length; $i++) {
            $variants[] = substr($name, 0, $i).substr($name, $i + 1);
        }

        // doubled
        for ($i = 0; $i < $length; $i++) {
            $variants[] = substr($name, 0, $i).$name[$i].substr($name, $i);
        }

        // swapped
        for ($i = 0; $i < $length - 1; $i++) {
            $variants[] = substr($name, 0, $i).$name[$i + 1].$name[$i].substr($name, $i + 2);
        }

        // substituted
        for ($i = 0; $i < $length; $i++) {
            for ($c = 97; $c <= 122; $c++) {
                $letter = chr($c);

                if ($letter === $name[$i]) {
                    continue;
                }

                $variants[] = substr($name, 0, $i).$letter.substr($name, $i + 1);
            }
        }

        $variants = array_values(array_unique(array_filter($variants, fn (string $v) => $v !== '')));

        return array_values(array_diff($variants, [$name]));
    }

    /**
     * Count the other TLDs that carry the exact same name, and whether every
     * domain with nameserver data points at one set (a single owner).
     *
     * @param  array<int, string>  $names
     * @return array<string, array{count:int, same_nameservers:bool}>
     */
    private function exactTldStats(array $names, string $snapshotDate): array
    {
        $sql = sprintf(
            'SELECT name AS base, uniqExact(tld) AS exact_tld_count, '
            ."countIf(ns != '') AS known_ns, uniqExactIf(ns, ns != '') AS ns_sets "
            .'FROM %s.zone_names '
            .'WHERE snapshot_date = %s AND tld != \'com\' AND name IN %s '
            .'GROUP BY name',
            $this->database,
            $this->sqlString($snapshotDate),
            $this->sqlArray($names),
        );

        $stats = [];

        foreach ($this->clickhouse->rowsFromSql($sql, self::QUERY_SETTINGS) as $row) {
            $base = strtolower((string) ($row['base'] ?? ''));

            if ($base === '') {
                continue;
            }

            $known = (int) ($row['known_ns'] ?? 0);
            $sets = (int) ($row['ns_sets'] ?? 0);

            $stats[$base] = [
                'count' => (int) ($row['exact_tld_count'] ?? 0),
                'same_nameservers' => $known >= 2 && $sets === 1,
            ];
        }

        return $stats;
    }

    /**
     * The same name on another TLD. The .com row is the dropped domain
     * itself and never counts.
     *
     * @param  array<int, string>  $names
     * @param  array<int, string>  $tlds
     * @return array<int, array<string, mixed>>
     */
    private function queryExact(array $names, string $snapshotDate, array $tlds): array
    {
        $sql = sprintf(
            'SELECT name AS base, tld, name, ns FROM %s.zone_names '
            .'WHERE snapshot_date = %s AND tld IN %s AND tld != \'com\' AND name IN %s '
            .'ORDER BY name, tld',
            $this->database,
            $this->sqlString($snapshotDate),
            $this->sqlArray($tlds),
            $this->sqlArray($names),
        );

        return $this->clickhouse->rowsFromSql($sql, self::QUERY_SETTINGS);
    }

    /**
     * Resolve an exact set of names across the snapshots in one indexed
     * lookup.
     *
     * @param  array<int, string>  $names
     * @param  array<int, string>  $tlds
     * @return array<int, array<string, mixed>>
     */
    private function queryNames(array $names, string $snapshotDate, array $tlds): array
    {
        if ($names === []) {
            return [];
        }

        $sql = sprintf(
            'SELECT name, tld, ns FROM %s.zone_names '
            .'WHERE snapshot_date = %s AND tld IN %s AND name IN %s '
            .'ORDER BY name, tld',
            $this->database,
            $this->sqlString($snapshotDate),
            $this->sqlArray($tlds),
            $this->sqlArray($names),
        );

        return $this->clickhouse->rowsFromSql($sql, self::QUERY_SETTINGS);
    }

    /**
     * Names that start with one of the dropped names. The primary key is
     * (snapshot_date, tld, name), so each prefix is an index range instead of
     * a full-table startsWith() scan.
     *
     * @param  array<int, string>  $names
     * @param  array<int, string>  $tlds
     * @return array<int, array<string, mixed>>
     */
    private function queryPrefixes(array $names, string $snapshotDate, array $tlds): array
    {
        if ($names === []) {
            return [];
        }

        $ranges = [];

        foreach ($names as $name) {
            $range = '(name >= '.$this->sqlString($name)
                .' AND name < '.$this->sqlString($name.'{');

            if (strlen($name) <= self::SHORT_NAME_MAX_LENGTH) {
                $range .= ' AND length(name) - '.strlen($name)
                    .' BETWEEN 1 AND '.self::SHORT_MAX_ADDED_CHARS;
            }

            $ranges[] = $range.')';
        }

        $sql = sprintf(
            'SELECT name, tld, ns FROM %s.zone_names '
            .'WHERE snapshot_date = %s AND tld IN %s AND (%s) '
            .'ORDER BY name, tld LIMIT %d',
            $this->database,
            $this->sqlString($snapshotDate),
            $this->sqlArray($tlds),
            implode(' OR ', $ranges),
            self::MAX_RANGE_ROWS,
        );

        return $this->clickhouse->rowsFromSql($sql, self::QUERY_SETTINGS);
    }

    /**
     * Names that end with one of the dropped names. This runs only when the
     * name_rev_order projection is materialized; the caller checks first.
     *
     * @param  array<int, string>  $names
     * @param  array<int, string>  $tlds
     * @return array<int, array<string, mixed>>
     */
    private function querySuffixes(array $names, string $snapshotDate, array $tlds): array
    {
        if ($names === []) {
            return [];
        }

        $ranges = [];

        foreach ($names as $name) {
            $reverse = strrev($name);
            $range = '(name_rev >= '.$this->sqlString($reverse)
                .' AND name_rev < '.$this->sqlString($reverse.'{');

            if (strlen($name) <= self::SHORT_NAME_MAX_LENGTH) {
                $range .= ' AND length(name) - '.strlen($name)
                    .' BETWEEN 1 AND '.self::SHORT_MAX_ADDED_CHARS;
            }

            $ranges[] = $range.')';
        }

        $sql = sprintf(
            'SELECT name, tld, ns FROM %s.zone_names '
            .'WHERE snapshot_date = %s AND tld IN %s AND (%s) '
            .'ORDER BY name, tld LIMIT %d',
            $this->database,
            $this->sqlString($snapshotDate),
            $this->sqlArray($tlds),
            implode(' OR ', $ranges),
            self::MAX_RANGE_ROWS,
        );

        return $this->clickhouse->rowsFromSql($sql, self::SUFFIX_SETTINGS);
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<int, string>  $tlds
     * @return array<int, array<string, mixed>>
     */
    private function queryContains(array $names, string $snapshotDate, array $tlds, int $limit): array
    {
        $sql = sprintf(
            'SELECT base, tld, name, ns FROM ('
            .'SELECT r.name AS base, z.tld AS tld, z.name AS name, z.ns AS ns '
            .'FROM %s.zone_names AS z '
            .'INNER JOIN (SELECT arrayJoin(%s) AS name) AS r '
            .'ON positionCaseInsensitive(z.name, r.name) > 0 AND z.name != r.name '
            .'WHERE z.snapshot_date = %s AND z.tld IN %s'
            .') ORDER BY base, name, tld LIMIT %d BY base',
            $this->database,
            $this->sqlArray($names),
            $this->sqlString($snapshotDate),
            $this->sqlArray($tlds),
            $limit,
        );

        return $this->clickhouse->rowsFromSql($sql, self::QUERY_SETTINGS);
    }

    /**
     * @param  array<int, string>  $values
     */
    private function sqlArray(array $values): string
    {
        return '['.implode(',', array_map(fn (string $value) => $this->sqlString($value), $values)).']';
    }

    private function sqlString(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
