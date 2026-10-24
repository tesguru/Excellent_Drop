<?php

namespace Tests\Unit;

use App\Services\Candidates\BrandRiskDetector;
use Tests\TestCase;

class BrandRiskDetectorTest extends TestCase
{
    public function test_only_the_curated_list_flags_a_brand(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'brand-terms-');

        file_put_contents($path, "# comment\nAcme\n");

        try {
            $detector = new BrandRiskDetector($path);

            $this->assertTrue($detector->isAtRisk('acme'));
            $this->assertTrue($detector->isAtRisk('ACME'));

            // A dictionary word that is not on the list stays unflagged.
            $this->assertFalse($detector->isAtRisk('plumbing'));
            $this->assertFalse($detector->isAtRisk('acmeplumbing'));
        } finally {
            @unlink($path);
        }
    }

    public function test_a_missing_list_flags_nothing(): void
    {
        $detector = new BrandRiskDetector(sys_get_temp_dir().'/dropwatch-missing-brand-terms.txt');

        $this->assertFalse($detector->isAtRisk('acme'));
    }
}
