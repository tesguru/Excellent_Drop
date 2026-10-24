<?php

namespace Tests\Unit;

use App\Services\Candidates\Scorer;
use Tests\TestCase;

class ScorerTest extends TestCase
{
    public function test_names_of_four_characters_or_fewer_get_the_larger_short_bonus(): void
    {
        $scorer = app(Scorer::class);
        $ownership = ['known' => false, 'independent' => false, 'same_owner' => false];

        $short = $scorer->score('qzkv', [], [], $ownership, 0);
        $medium = $scorer->score('qzkvw', [], [], $ownership, 0);

        $this->assertSame(24.0, $short['breakdown']['very_short']);
        $this->assertSame(0.0, $short['breakdown']['short_name']);

        $this->assertSame(0.0, $medium['breakdown']['very_short']);
        $this->assertSame(6.0, $medium['breakdown']['short_name']);

        $this->assertGreaterThan($medium['score'], $short['score']);
    }

    public function test_the_exact_tld_bonus_needs_two_tlds_and_independent_nameservers(): void
    {
        $scorer = app(Scorer::class);
        $ownership = ['known' => false, 'independent' => false, 'same_owner' => false];

        $one = $scorer->score('qzkvw', [], [], $ownership, 1);
        $two = $scorer->score('qzkvw', [], [], $ownership, 2);
        $twoSameOwner = $scorer->score('qzkvw', [], [], $ownership, 2, true);
        $four = $scorer->score('qzkvw', [], [], $ownership, 4);

        $this->assertSame(0.0, $one['breakdown']['exact_tld_count']);
        $this->assertSame(24.0, $two['breakdown']['exact_tld_count']);
        $this->assertSame(0.0, $twoSameOwner['breakdown']['exact_tld_count']);
        $this->assertSame(48.0, $four['breakdown']['exact_tld_count']);
    }
}
