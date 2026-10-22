<?php

namespace App\Services\Candidates;

use App\Services\ClickHouse\ClickHouseClient;

/**
 * Finds the weaker versions of a removed .com name.
 *
 * A weaker version is a registered domain built around the same name on
 * another TLD, with a hyphen inserted, that starts with, ends with or is a
 * plural of the name.
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

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly ClickHouseClient $clickhouse,
        private readonly array $config,
        private readonly string $database = 'zones',
    ) {}

    /**
     * Find every weaker version of a name.
     *
     * @param  array<int, string>  $tlds  TLDs that have a snapshot for the given date
     * @return array{
     *     weaker_found: array<int, string>,
     *     weaker_types: array<int, string>,
     *     details: array<string, array{domain:string, tld:string, name:string, signals:array<int,string>, added_chars:int}>
     * }
     */
    public function find(string $name, string $snapshotDate, array $tlds): array
    {
        $details = [];
        $limit = (int) ($this->config['max_per_rule'] ?? 50);

        // Short names produce an enormous amount of prefix/suffix matches, so
        // they are restricted to the closest ones and capped afterwards.
        $isShort = strlen($name) <= self::SHORT_NAME_MAX_LENGTH;
        $maxAdded = $isShort ? self::SHORT_MAX_ADDED_CHARS : null;

        $collect = function (array $rows, string $signal, callable $addedChars) use (&$details): void {
            foreach ($rows as $row) {
                $tld = (string) ($row['tld'] ?? '');
                $label = (string) ($row['name'] ?? '');

                if ($tld === '' || $label === '') {
                    continue;
                }

                $domain = $label.'.'.$tld;

                if (! isset($details[$domain])) {
                    $details[$domain] = [
                        'domain' => $domain,
                        'tld' => $tld,
                        'name' => $label,
                        'signals' => [],
                        'added_chars' => PHP_INT_MAX,
                    ];
                }

                if (! in_array($signal, $details[$domain]['signals'], true)) {
                    $details[$domain]['signals'][] = $signal;
                }

                $added = (int) $addedChars($label);

                if ($added < $details[$domain]['added_chars']) {
                    $details[$domain]['added_chars'] = $added;
                }
            }
        };

        // 1. The same name on another TLD.
        $collect(
            $this->queryByName($name, $snapshotDate, $tlds, excludeTld: 'com'),
            self::SIGNAL_OTHER_TLD,
            fn (string $other) => 0,
        );

        // 2. The same name with a hyphen inserted at any split point.
        $hyphens = $this->hyphenVariants($name);

        if ($hyphens !== []) {
            $collect(
                $this->queryByNames($hyphens, $snapshotDate, $tlds),
                self::SIGNAL_HYPHEN,
                fn (string $other) => 1,
            );
        }

        // 3. Names that start with the dropped name.
        $collect(
            $this->queryStartsWith($name, $snapshotDate, $tlds, $limit, $maxAdded),
            self::SIGNAL_STARTS_WITH,
            fn (string $other) => max(1, strlen($other) - strlen($name)),
        );

        // 4. Names that end with the dropped name (searched through name_rev).
        $collect(
            $this->queryEndsWith($name, $snapshotDate, $tlds, $limit, $maxAdded),
            self::SIGNAL_ENDS_WITH,
            fn (string $other) => max(1, strlen($other) - strlen($name)),
        );

        // 5. Plural or singular of the dropped name.
        $plurals = $this->pluralVariants($name);

        if ($plurals !== []) {
            $collect(
                $this->queryByNames($plurals, $snapshotDate, $tlds),
                self::SIGNAL_PLURAL,
                fn (string $other) => abs(strlen($other) - strlen($name)),
            );
        }

        // Phase 2 hooks - disabled unless explicitly enabled.
        if ($this->config['phase2_enabled'] ?? false) {
            $misspellings = $this->misspellingVariants($name);

            if ($misspellings !== []) {
                $collect(
                    $this->queryByNames($misspellings, $snapshotDate, $tlds),
                    self::SIGNAL_MISSPELLING,
                    fn (string $other) => 0,
                );
            }

            $collect(
                $this->queryContains($name, $snapshotDate, $tlds, $limit),
                self::SIGNAL_CONTAINS,
                fn (string $other) => max(1, strlen($other) - strlen($name)),
            );
        }

        if ($isShort) {
            // The unrestricted rules are kept first, then the closest prefix
            // and suffix matches, when a short name matches more versions
            // than we are willing to store.
            $rank = fn (array $detail): int => array_intersect(
                $detail['signals'],
                [self::SIGNAL_OTHER_TLD, self::SIGNAL_HYPHEN, self::SIGNAL_PLURAL],
            ) === [] ? 1 : 0;

            uasort($details, fn (array $a, array $b) => [$rank($a), $a['added_chars'], $a['domain']] <=> [$rank($b), $b['added_chars'], $b['domain']]);
            $details = array_slice($details, 0, self::SHORT_MAX_WEAKER, true);
        } else {
            ksort($details);
        }

        $weakerFound = [];
        $weakerTypes = [];

        foreach ($details as $domain => $detail) {
            $weakerFound[] = $domain;
            $weakerTypes[] = implode(',', $detail['signals']);
        }

        return [
            'weaker_found' => $weakerFound,
            'weaker_types' => $weakerTypes,
            'details' => $details,
        ];
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
     * @param  array<int, string>  $tlds
     * @return array<int, array<string, mixed>>
     */
    private function queryByName(string $name, string $snapshotDate, array $tlds, ?string $excludeTld = null): array
    {
        $sql = sprintf(
            'SELECT tld, name FROM %s.zone_names '
            .'WHERE snapshot_date = {d:Date} AND name = {n:String} AND tld IN ({tlds:Array(String)})',
            $this->database,
        );

        $params = [
            'd' => $snapshotDate,
            'n' => $name,
            'tlds' => $tlds,
        ];

        if ($excludeTld !== null) {
            $sql .= ' AND tld != {exclude:String}';
            $params['exclude'] = $excludeTld;
        }

        $sql .= ' ORDER BY tld LIMIT 100';

        return $this->clickhouse->rows($sql, $params);
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<int, string>  $tlds
     * @return array<int, array<string, mixed>>
     */
    private function queryByNames(array $names, string $snapshotDate, array $tlds): array
    {
        if ($names === [] || $tlds === []) {
            return [];
        }

        $sql = sprintf(
            'SELECT tld, name FROM %s.zone_names '
            .'WHERE snapshot_date = {d:Date} AND name IN ({names:Array(String)}) AND tld IN ({tlds:Array(String)}) '
            .'ORDER BY name, tld LIMIT 500',
            $this->database,
        );

        return $this->clickhouse->rows($sql, [
            'd' => $snapshotDate,
            'names' => $names,
            'tlds' => $tlds,
        ]);
    }

    /**
     * @param  array<int, string>  $tlds
     * @return array<int, array<string, mixed>>
     */
    private function queryStartsWith(string $name, string $snapshotDate, array $tlds, int $limit, ?int $maxAdded = null): array
    {
        $sql = sprintf(
            'SELECT tld, name FROM %s.zone_names '
            .'WHERE snapshot_date = {d:Date} AND startsWith(name, {n:String}) AND name != {n:String} '
            .'AND tld IN ({tlds:Array(String)})',
            $this->database,
        );

        $params = [
            'd' => $snapshotDate,
            'n' => $name,
            'tlds' => $tlds,
        ];

        if ($maxAdded !== null) {
            $sql .= ' AND length(name) - length({n:String}) BETWEEN 1 AND {max:UInt8}';
            $params['max'] = $maxAdded;
        }

        $sql .= ' ORDER BY name, tld LIMIT {lim:UInt32}';
        $params['lim'] = $limit;

        return $this->clickhouse->rows($sql, $params);
    }

    /**
     * @param  array<int, string>  $tlds
     * @return array<int, array<string, mixed>>
     */
    private function queryEndsWith(string $name, string $snapshotDate, array $tlds, int $limit, ?int $maxAdded = null): array
    {
        $sql = sprintf(
            'SELECT tld, name FROM %s.zone_names '
            .'WHERE snapshot_date = {d:Date} AND startsWith(name_rev, {rev:String}) AND name != {n:String} '
            .'AND tld IN ({tlds:Array(String)})',
            $this->database,
        );

        $params = [
            'd' => $snapshotDate,
            'rev' => strrev($name),
            'n' => $name,
            'tlds' => $tlds,
        ];

        if ($maxAdded !== null) {
            $sql .= ' AND length(name) - length({n:String}) BETWEEN 1 AND {max:UInt8}';
            $params['max'] = $maxAdded;
        }

        $sql .= ' ORDER BY name, tld LIMIT {lim:UInt32}';
        $params['lim'] = $limit;

        return $this->clickhouse->rows($sql, $params);
    }

    /**
     * @param  array<int, string>  $tlds
     * @return array<int, array<string, mixed>>
     */
    private function queryContains(string $name, string $snapshotDate, array $tlds, int $limit): array
    {
        $sql = sprintf(
            'SELECT tld, name FROM %s.zone_names '
            .'WHERE snapshot_date = {d:Date} AND positionCaseInsensitive(name, {n:String}) > 0 AND name != {n:String} '
            .'AND tld IN ({tlds:Array(String)}) ORDER BY name, tld LIMIT {lim:UInt32}',
            $this->database,
        );

        return $this->clickhouse->rows($sql, [
            'd' => $snapshotDate,
            'n' => $name,
            'tlds' => $tlds,
            'lim' => $limit,
        ]);
    }
}
