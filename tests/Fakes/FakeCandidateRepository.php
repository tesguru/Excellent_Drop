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

    public function forDate(string $date, int $limit = 500, bool $shortOnly = false): array
    {
        if (! $shortOnly) {
            return $this->candidates;
        }

        return array_values(array_filter(
            $this->candidates,
            fn (array $candidate) => ! empty($candidate['is_short']),
        ));
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
