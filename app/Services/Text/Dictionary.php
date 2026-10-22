<?php

namespace App\Services\Text;

/**
 * Small wrapper around an English word list.
 *
 * The list is used for name quality scoring (single dictionary word or a
 * clean pair of words) and for the brand risk heuristic.
 */
class Dictionary
{
    /** @var array<int, string> */
    private const CANDIDATE_PATHS = [
        '/usr/share/dict/words',
        '/usr/share/dict/american-english',
        '/usr/share/dict/web2',
    ];

    /** @var array<string, true>|null */
    private ?array $words = null;

    /**
     * @param  array<int, string>  $extraPaths
     */
    public function __construct(private readonly array $extraPaths = []) {}

    public function isWord(string $word): bool
    {
        $word = strtolower($word);

        if ($word === '' || strlen($word) > 30) {
            return false;
        }

        return isset($this->words()[$word]);
    }

    /**
     * Split a concatenated label into dictionary words.
     *
     * Returns an empty array when the label cannot be decomposed.
     *
     * @return array<int, string>
     */
    public function decompose(string $name): array
    {
        $name = strtolower($name);

        if ($name === '' || strlen($name) > 40) {
            return [];
        }

        if ($this->isWord($name)) {
            return [$name];
        }

        $length = strlen($name);

        for ($split = 2; $split <= $length - 2; $split++) {
            $left = substr($name, 0, $split);
            $right = substr($name, $split);

            if ($this->isWord($left) && $this->isWord($right)) {
                return [$left, $right];
            }
        }

        return [];
    }

    /**
     * @return array<string, true>
     */
    private function words(): array
    {
        if ($this->words !== null) {
            return $this->words;
        }

        $words = [];

        foreach ($this->candidatePaths() as $path) {
            if (! is_readable($path)) {
                continue;
            }

            $handle = fopen($path, 'rb');

            if ($handle === false) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $line = strtolower(trim($line));

                if ($line === '' || preg_match('/^[a-z]+$/', $line) !== 1) {
                    continue;
                }

                $words[$line] = true;
            }

            fclose($handle);
        }

        return $this->words = $words;
    }

    /**
     * The extra paths come first so a curated list always wins.
     *
     * @return array<int, string>
     */
    private function candidatePaths(): array
    {
        return array_values(array_unique(array_merge($this->extraPaths, self::CANDIDATE_PATHS)));
    }
}
