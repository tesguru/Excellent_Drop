<?php

namespace App\Services\Candidates;

use App\Services\ClickHouse\ClickHouseClient;

/**
 * Finds the weaker versions of removed .com names.
 *
 * A weaker version is a registered domain built around the same name on
 * another TLD, with a hyphen inserted, that starts with, ends with or is a
 * plural of the name.
 *
 * Queries are batched: one query per rule for a whole chunk of removed names,
 * never one query per name.
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
     *     nameservers: array<string, array<int, string>>
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
            $this->queryOtherTld($names, $snapshotDate, $tlds, $limit),
            self::SIGNAL_OTHER_TLD,
            fn (string $base, string $label): int => 0,
        );

        // 2. The same name with a hyphen inserted at any split point.
        $hyphens = [];

        foreach ($names as $name) {
            foreach ($this->hyphenVariants($name) as $variant) {
                $hyphens[] = [$name, $variant];
            }
        }

        $this->collectPairs(
            $details,
            $hyphens,
            $snapshotDate,
            $tlds,
            $limit,
            self::SIGNAL_HYPHEN,
            fn (string $base, string $label): int => 1,
        );

        // 3. Names that start with the dropped name.
        $this->collect(
            $details,
            $this->queryStartsWith($names, $snapshotDate, $tlds, $limit),
            self::SIGNAL_STARTS_WITH,
            fn (string $base, string $label): int => max(1, strlen($label) - strlen($base)),
        );

        // 4. Names that end with the dropped name (searched through name_rev).
        $this->collect(
            $details,
            $this->queryEndsWith($names, $snapshotDate, $tlds, $limit),
            self::SIGNAL_ENDS_WITH,
            fn (string $base, string $label): int => max(1, strlen($label) - strlen($base)),
        );

        // 5. Plural or singular of the dropped name.
        $plurals = [];

        foreach ($names as $name) {
            foreach ($this->pluralVariants($name) as $variant) {
                $plurals[] = [$name, $variant];
            }
        }

        $this->collectPairs(
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
                    $misspellings[] = [$name, $variant];
                }
            }

            $this->collectPairs(
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
            );
        }

        return $this->assemble($names, $details);
    }

    /**
     * Find every weaker version of a single name.
     *
     * @param  array<int, string>  $tlds
     * @return array{
     *     weaker_found: array<int, string>,
     *     weaker_types: array<int, string>,
     *     details: array<string, array{domain:string, tld:string, name:string, signals:array<int,string>, added_chars:int, nameservers:array<int,string>}>,
     *     nameservers: array<string, array<int, string>>
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
     * @return array<string, array{
     *     weaker_found: array<int, string>,
     *     weaker_types: array<int, string>,
     *     details: array<string, array{domain:string, tld:string, name:string, signals:array<int,string>, added_chars:int, nameservers:array<int,string>}>,
     *     nameservers: array<string, array<int, string>>
     * }>
     */
    private function assemble(array $names, array $details): array
    {
        $results = [];

        foreach ($names as $name) {
            $found = $details[$name] ?? [];

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
            ];
        }

        return $results;
    }

    /**
     * @return array{weaker_found: array<int, string>, weaker_types: array<int, string>, details: array<string, array<string, mixed>>, nameservers: array<string, array<int, string>>}
     */
    private function emptyResult(): array
    {
        return [
            'weaker_found' => [],
            'weaker_types' => [],
            'details' => [],
            'nameservers' => [],
        ];
    }

    /**
     * @param  array<string, array<string, array{domain:string, tld:string, name:string, signals:array<int,string>, added_chars:int, nameservers:array<int,string>}>>  $details
     * @param  array<int, array<string, mixed>>  $rows
     * @param  callable(string, string): int  $addedChars
     */
    private function collect(array &$details, array $rows, string $signal, callable $addedChars): void
    {
        foreach ($rows as $row) {
            $base = strtolower((string) ($row['base'] ?? ''));
            $tld = strtolower((string) ($row['tld'] ?? ''));
            $label = strtolower((string) ($row['name'] ?? ''));

            if ($base === '' || $tld === '' || $label === '') {
                continue;
            }

            $domain = $label.'.'.$tld;

            if (! isset($details[$base][$domain])
                && isset($details[$base])
                && count($details[$base]) >= self::MAX_DETAILS_PER_NAME) {
                continue;
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
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $details
     * @param  array<int, array{0:string, 1:string}>  $pairs
     * @param  callable(string, string): int  $addedChars
     */
    private function collectPairs(
        array &$details,
        array $pairs,
        string $snapshotDate,
        array $tlds,
        int $limit,
        string $signal,
        callable $addedChars,
    ): void {
        if ($pairs === []) {
            return;
        }

        $this->collect(
            $details,
            $this->queryPairs($pairs, $snapshotDate, $tlds, $limit),
            $signal,
            $addedChars,
        );
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
     * @param  array<int, string>  $names
     * @param  array<int, string>  $tlds
     * @return array<int, array<string, mixed>>
     */
    private function queryOtherTld(array $names, string $snapshotDate, array $tlds, int $limit): array
    {
        $sql = sprintf(
            'SELECT base, tld, name, ns FROM ('
            .'SELECT z.name AS base, z.tld AS tld, z.name AS name, z.ns AS ns '
            .'FROM %s.zone_names AS z '
            .'WHERE z.snapshot_date = %s AND z.tld IN %s AND z.name IN %s AND z.tld != \'com\''
            .') ORDER BY base, tld LIMIT %d BY base',
            $this->database,
            $this->sqlString($snapshotDate),
            $this->sqlArray($tlds),
            $this->sqlArray($names),
            $limit,
        );

        return $this->clickhouse->rowsFromSql($sql, self::QUERY_SETTINGS);
    }

    /**
     * @param  array<int, array{0:string, 1:string}>  $pairs
     * @param  array<int, string>  $tlds
     * @return array<int, array<string, mixed>>
     */
    private function queryPairs(array $pairs, string $snapshotDate, array $tlds, int $limit): array
    {
        $seen = [];
        $values = [];

        foreach ($pairs as [$base, $variant]) {
            if ($base === '' || $variant === '') {
                continue;
            }

            $key = $base."\0".$variant;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $values[] = '('.$this->sqlString($base).','.$this->sqlString($variant).')';
        }

        if ($values === []) {
            return [];
        }

        $sql = sprintf(
            'SELECT base, tld, name, ns FROM ('
            .'SELECT tupleElement(v.pair, 1) AS base, z.tld AS tld, z.name AS name, z.ns AS ns '
            .'FROM %s.zone_names AS z '
            .'INNER JOIN (SELECT arrayJoin(%s) AS pair) AS v ON z.name = tupleElement(v.pair, 2) '
            .'WHERE z.snapshot_date = %s AND z.tld IN %s'
            .') ORDER BY base, name, tld LIMIT %d BY base',
            $this->database,
            '['.implode(',', $values).']',
            $this->sqlString($snapshotDate),
            $this->sqlArray($tlds),
            $limit,
        );

        return $this->clickhouse->rowsFromSql($sql, self::QUERY_SETTINGS);
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<int, string>  $tlds
     * @return array<int, array<string, mixed>>
     */
    private function queryStartsWith(array $names, string $snapshotDate, array $tlds, int $limit): array
    {
        $sql = sprintf(
            'SELECT base, tld, name, ns FROM ('
            .'SELECT r.name AS base, z.tld AS tld, z.name AS name, z.ns AS ns '
            .'FROM %s.zone_names AS z '
            .'INNER JOIN (SELECT arrayJoin(%s) AS name) AS r '
            .'ON startsWith(z.name, r.name) AND z.name != r.name '
            .'WHERE z.snapshot_date = %s AND z.tld IN %s'
            .') WHERE (length(base) > %d OR length(name) - length(base) BETWEEN 1 AND %d) '
            .'ORDER BY base, name, tld LIMIT %d BY base',
            $this->database,
            $this->sqlArray($names),
            $this->sqlString($snapshotDate),
            $this->sqlArray($tlds),
            self::SHORT_NAME_MAX_LENGTH,
            self::SHORT_MAX_ADDED_CHARS,
            $limit,
        );

        return $this->clickhouse->rowsFromSql($sql, self::QUERY_SETTINGS);
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<int, string>  $tlds
     * @return array<int, array<string, mixed>>
     */
    private function queryEndsWith(array $names, string $snapshotDate, array $tlds, int $limit): array
    {
        $values = [];

        foreach ($names as $name) {
            $values[] = '('.$this->sqlString($name).','.$this->sqlString(strrev($name)).')';
        }

        $sql = sprintf(
            'SELECT base, tld, name, ns FROM ('
            .'SELECT tupleElement(v.pair, 1) AS base, z.tld AS tld, z.name AS name, z.ns AS ns '
            .'FROM %s.zone_names AS z '
            .'INNER JOIN (SELECT arrayJoin(%s) AS pair) AS v '
            .'ON startsWith(z.name_rev, tupleElement(v.pair, 2)) AND z.name != tupleElement(v.pair, 1) '
            .'WHERE z.snapshot_date = %s AND z.tld IN %s'
            .') WHERE (length(base) > %d OR length(name) - length(base) BETWEEN 1 AND %d) '
            .'ORDER BY base, name, tld LIMIT %d BY base',
            $this->database,
            '['.implode(',', $values).']',
            $this->sqlString($snapshotDate),
            $this->sqlArray($tlds),
            self::SHORT_NAME_MAX_LENGTH,
            self::SHORT_MAX_ADDED_CHARS,
            $limit,
        );

        return $this->clickhouse->rowsFromSql($sql, self::QUERY_SETTINGS);
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
