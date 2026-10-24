<?php

namespace Tests\Fakes;

use App\Services\Zone\ZoneSearch;

class FakeZoneSearch extends ZoneSearch
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $tldOptions
     */
    public function __construct(
        private readonly array $payload = [],
        private readonly array $tldOptions = ['com', 'net'],
    ) {}

    public function search(array $filters): array
    {
        return array_merge([
            'results' => [],
            'page' => 1,
            'per_page' => 100,
            'has_more' => false,
            'error' => null,
            'latest' => '2026-10-24',
        ], $this->payload);
    }

    public function tlds(?string $snapshotDate): array
    {
        return $this->tldOptions;
    }
}
