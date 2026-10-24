<?php

namespace App\Services\Candidates;

use App\Services\Text\Dictionary;

/**
 * Scores a candidate. Higher is better.
 *
 * The ranking follows the strategy order: the number of weaker versions,
 * then how close the weaker versions are, then independent owners, then name
 * quality.
 */
class Scorer
{
    /**
     * Weight of every scoring component.
     */
    public const WEIGHTS = [
        'weaker_count' => 6.0,
        'closeness' => -2.0,
        'exact_tld_count' => 12.0,
        'independent_owners' => 18.0,
        'same_owner' => -15.0,
        'single_word' => 18.0,
        'word_pair' => 10.0,
        'not_dictionary' => -4.0,
        'digits' => -10.0,
        'hyphen' => -6.0,
        'very_short' => 24.0,
        'short_name' => 6.0,
        'long_name' => -8.0,
        'brand_risk' => -25.0,
    ];

    private const MAX_COUNTED_WEAKER = 25;

    private const MAX_COUNTED_ADDED_CHARS = 40;

    private const MAX_COUNTED_TLDS = 6;

    public function __construct(
        private readonly Dictionary $dictionary,
        private readonly BrandRiskDetector $brandRisk,
    ) {}

    /**
     * @param  array<int, string>  $weakerFound  full domains
     * @param  array<int, string>  $weakerTypes  signal per weaker version, parallel to $weakerFound
     * @param  array{independent:bool, same_owner:bool, known:bool}  $ownership
     * @return array{score:float, breakdown:array<string, float>, brand_risk:bool, exact_tld_count:int}
     */
    public function score(
        string $name,
        array $weakerFound,
        array $weakerTypes,
        array $ownership,
        int $exactTldCount = 0,
        bool $sameNameservers = false,
    ): array {
        $breakdown = [];

        $count = count($weakerFound);
        $breakdown['weaker_count'] = min($count, self::MAX_COUNTED_WEAKER) * self::WEIGHTS['weaker_count'];

        $addedChars = $this->averageAddedChars($weakerFound, $weakerTypes);
        $breakdown['closeness'] = min($addedChars, self::MAX_COUNTED_ADDED_CHARS) * self::WEIGHTS['closeness'];

        // Strategy 2 bonus: the exact name lives on two or more other TLDs.
        // One owner across all of them is one registration strategy, not
        // independent demand, so it earns nothing.
        $breakdown['exact_tld_count'] = $exactTldCount >= 2 && ! $sameNameservers
            ? min($exactTldCount, self::MAX_COUNTED_TLDS) * self::WEIGHTS['exact_tld_count']
            : 0.0;

        if ($ownership['known'] ?? false) {
            $breakdown['independent_owners'] = ($ownership['independent'] ?? false)
                ? self::WEIGHTS['independent_owners']
                : 0.0;
            $breakdown['same_owner'] = ($ownership['same_owner'] ?? false)
                ? self::WEIGHTS['same_owner']
                : 0.0;
        } else {
            $breakdown['independent_owners'] = 0.0;
            $breakdown['same_owner'] = 0.0;
        }

        $decomposition = $this->dictionary->decompose($name);
        $isSingleWord = count($decomposition) === 1;
        $isPair = count($decomposition) === 2;

        $breakdown['single_word'] = $isSingleWord ? self::WEIGHTS['single_word'] : 0.0;
        $breakdown['word_pair'] = $isPair ? self::WEIGHTS['word_pair'] : 0.0;
        $breakdown['not_dictionary'] = $decomposition === [] ? self::WEIGHTS['not_dictionary'] : 0.0;

        $hasDigits = preg_match('/\d/', $name) === 1;
        $breakdown['digits'] = $hasDigits ? self::WEIGHTS['digits'] : 0.0;

        $hasHyphen = str_contains($name, '-');
        $breakdown['hyphen'] = $hasHyphen ? self::WEIGHTS['hyphen'] : 0.0;

        $length = strlen($name);
        $breakdown['very_short'] = $length <= 4 ? self::WEIGHTS['very_short'] : 0.0;
        $breakdown['short_name'] = $length > 4 && $length <= 8 ? self::WEIGHTS['short_name'] : 0.0;
        $breakdown['long_name'] = $length > 15 ? self::WEIGHTS['long_name'] : 0.0;

        $brandRisk = $this->brandRisk->isAtRisk($name);
        $breakdown['brand_risk'] = $brandRisk ? self::WEIGHTS['brand_risk'] : 0.0;

        $score = array_sum($breakdown);

        return [
            'score' => round($score, 2),
            'breakdown' => $breakdown,
            'brand_risk' => $brandRisk,
            'exact_tld_count' => $exactTldCount,
        ];
    }

    /**
     * Average number of characters that were added to build the weaker
     * versions. Fewer added characters ranks higher.
     *
     * @param  array<int, string>  $weakerFound
     * @param  array<int, string>  $weakerTypes
     */
    private function averageAddedChars(array $weakerFound, array $weakerTypes): float
    {
        if ($weakerFound === []) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($weakerFound as $index => $domain) {
            $label = substr($domain, 0, strrpos($domain, '.') ?: strlen($domain));
            $signals = explode(',', (string) ($weakerTypes[$index] ?? ''));

            $added = PHP_INT_MAX;

            foreach ($signals as $signal) {
                $added = min($added, $this->addedChars($label, $signal));
            }

            if ($added === PHP_INT_MAX) {
                $added = 0;
            }

            $total += $added;
        }

        return $total / count($weakerFound);
    }

    private function addedChars(string $label, string $signal): int
    {
        return match ($signal) {
            WeakerSearcher::SIGNAL_OTHER_TLD => 0,
            WeakerSearcher::SIGNAL_HYPHEN => 1,
            WeakerSearcher::SIGNAL_PLURAL => 1,
            WeakerSearcher::SIGNAL_MISSPELLING => 0,
            default => 0,
        };
    }
}
