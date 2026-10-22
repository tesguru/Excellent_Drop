<?php

namespace Tests\Unit;

use App\Services\Candidates\WeakerSearcher;
use App\Services\ClickHouse\ClickHouseClient;
use Tests\TestCase;

class WeakerSearcherTest extends TestCase
{
    public function test_it_generates_a_hyphen_variant_for_every_split_point(): void
    {
        $variants = $this->searcher()->hyphenVariants('acme');

        $this->assertSame(['a-cme', 'ac-me', 'acm-e'], $variants);
    }

    public function test_it_generates_the_plural_and_the_singular(): void
    {
        $searcher = $this->searcher();

        $this->assertSame(['acmes'], $searcher->pluralVariants('acme'));
        $this->assertSame(['acme'], $searcher->pluralVariants('acmes'));
    }

    public function test_it_generates_misspellings(): void
    {
        $variants = $this->searcher()->misspellingVariants('ab');

        $this->assertContains('ba', $variants);
        $this->assertContains('aab', $variants);
        $this->assertContains('abb', $variants);
        $this->assertNotContains('ab', $variants);
    }

    private function searcher(): WeakerSearcher
    {
        return new WeakerSearcher(
            new ClickHouseClient(config('zones.clickhouse')),
            config('zones.weaker'),
            'zones',
        );
    }
}
