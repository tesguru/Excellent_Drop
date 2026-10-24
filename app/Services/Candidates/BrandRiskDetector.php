<?php

namespace App\Services\Candidates;

/**
 * Flags the names in the curated brand list for manual review.
 *
 * There is no trademark database available to the app, and a dictionary
 * heuristic flags every single word, so only storage/app/brand_terms.txt is
 * used.
 */
class BrandRiskDetector
{
    /** @var array<string, true>|null */
    private ?array $curated = null;

    public function __construct(private readonly string $curatedPath) {}

    public function isAtRisk(string $name): bool
    {
        return isset($this->curated()[strtolower(trim($name))]);
    }

    /**
     * @return array<string, true>
     */
    private function curated(): array
    {
        if ($this->curated !== null) {
            return $this->curated;
        }

        $terms = [];

        if (is_readable($this->curatedPath)) {
            $handle = fopen($this->curatedPath, 'rb');

            if ($handle !== false) {
                while (($line = fgets($handle)) !== false) {
                    $line = strtolower(trim($line));

                    if ($line !== '' && ! str_starts_with($line, '#')) {
                        $terms[$line] = true;
                    }
                }

                fclose($handle);
            }
        }

        return $this->curated = $terms;
    }
}
