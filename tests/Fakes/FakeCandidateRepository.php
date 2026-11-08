<?php

namespace Tests\Fakes;

use App\Services\CandidateRepository;
use App\Services\ClickHouse\ClickHouseClient;

/**
 * A candidate repository that answers with canned data so the feature tests
 * do not need a ClickHouse server.
 */
class FakeCandidateRepository extends CandidateRepository
{
    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<int, string>  $removed
     * @param  array<int, string>  $added
     */
    public function __construct(
        private readonly array $candidates = [],
        private readonly array $removed = [],
        private readonly array $added = [],
    ) {
        parent::__construct(new ClickHouseClient(config('zones.clickhouse')), 'zones');
    }

    public function forDate(string $date, int $limit = 500, bool $shortOnly = false, bool $exactOnly = false): array
    {
        $candidates = $this->candidates;

        if ($shortOnly) {
            $candidates = array_values(array_filter(
                $candidates,
                fn (array $candidate) => ! empty($candidate['is_short']),
            ));
        }

        if ($exactOnly) {
            $candidates = array_values(array_filter(
                $candidates,
                fn (array $candidate) => (int) ($candidate['exact_tld_count'] ?? 0) >= 2,
            ));
        }

        return $candidates;
    }

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
        $candidates = $this->candidates;

        if ($shortOnly) {
            $candidates = $this->filter($candidates, fn (array $candidate) => ! empty($candidate['is_short']));
        }

        if ($exactOnly) {
            $candidates = $this->filter($candidates, fn (array $candidate) => (int) ($candidate['exact_tld_count'] ?? 0) >= 2);
        }

        $candidates = $this->filterVariations($candidates, $minVariations, $maxVariations, $exactVariations);

        $terms = array_values(array_filter(preg_split('/\s+/', strtolower(trim($term))) ?: []));

        foreach ($terms as $part) {
            if (str_contains($part, '.')) {
                $part = (string) preg_replace('/\.[^.]+$/', '', $part);
            }

            if ($part === '') {
                continue;
            }

            $candidates = $this->filter(
                $candidates,
                fn (array $candidate) => str_contains(strtolower((string) ($candidate['name'] ?? '')), $part),
            );
        }

        usort($candidates, function (array $a, array $b): int {
            return ((float) ($b['score'] ?? 0) <=> (float) ($a['score'] ?? 0))
                ?: strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $total = count($candidates);
        $perPage = max(1, $perPage);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);

        return [
            'candidates' => array_slice($candidates, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  callable(array<string, mixed>): bool  $callback
     * @return array<int, array<string, mixed>>
     */
    private function filter(array $candidates, callable $callback): array
    {
        return array_values(array_filter($candidates, $callback));
    }

    /**
     * An exact count wins over the min and max bounds, matching the query.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function filterVariations(array $candidates, ?int $min, ?int $max, ?int $exact): array
    {
        if ($exact !== null) {
            return $this->filter($candidates, fn (array $candidate) => $this->variationCount($candidate) === $exact);
        }

        if ($min !== null) {
            $candidates = $this->filter($candidates, fn (array $candidate) => $this->variationCount($candidate) >= $min);
        }

        if ($max !== null) {
            $candidates = $this->filter($candidates, fn (array $candidate) => $this->variationCount($candidate) <= $max);
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function variationCount(array $candidate): int
    {
        return (int) ($candidate['weaker_count'] ?? count((array) ($candidate['weaker_found'] ?? [])));
    }

    public function forRange(string $from, string $to, int $limit = 1000): array
    {
        return $this->candidates;
    }

    public function search(string $term, int $limit = 200): array
    {
        if (trim($term) === '') {
            return [];
        }

        return $this->candidates;
    }

    public function removedNames(string $date, int $limit = 2000): array
    {
        return $this->removed;
    }

    public function addedNames(string $date, int $limit = 2000): array
    {
        return $this->added;
    }

    public function removedCount(string $from, string $to): int
    {
        return count($this->removed);
    }

    public function countsForDate(string $date): array
    {
        return [
            'removed' => count($this->removed),
            'added' => count($this->added),
            'candidates' => count($this->candidates),
        ];
    }

    public function tldsForDate(string $date): array
    {
        return ['com'];
    }

    public function candidateDates(int $limit = 60): array
    {
        return [now()->format('Y-m-d')];
    }
}
