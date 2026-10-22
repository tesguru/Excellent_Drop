<?php

namespace App\Services\Candidates;

use App\Services\Text\Dictionary;

/**
 * Heuristic brand risk detector.
 *
 * There is no trademark database available to the app, so a name is flagged
 * for manual brand review when it is a single dictionary word (the shape a
 * trademark is most likely to take) or when it appears in the optional
 * curated list at storage/app/brand_terms.txt.
 */
class BrandRiskDetector
{
    /** @var array<string, true>|null */
    private ?array $curated = null;

    public function __construct(
        private readonly Dictionary $dictionary,
        private readonly string $curatedPath,
    ) {}

    public function isAtRisk(string $name): bool
    {
        $name = strtolower($name);

        if (isset($this->curated()[$name])) {
            return true;
        }

        if (preg_match('/\d/', $name) === 1) {
            return false;
        }

        // A single dictionary word is the most likely trademark shape.
        return $this->dictionary->isWord($name) && strlen($name) >= 4;
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
