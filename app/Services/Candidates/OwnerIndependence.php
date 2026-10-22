<?php

namespace App\Services\Candidates;

/**
 * Works out whether the weaker versions of a name belong to the same owner.
 *
 * The flag is only raised when we actually have nameserver data for the
 * weaker versions, so a missing lookup never hides a candidate.
 */
class OwnerIndependence
{
    /**
     * @param  array<string, array<int, string>>  $nameservers  domain => nameservers
     * @return array{independent:bool, same_owner:bool, known:bool, distinct_owners:int}
     */
    public function analyze(array $nameservers): array
    {
        $sets = [];

        foreach ($nameservers as $domain => $servers) {
            $normalized = $this->normalize($servers);

            if ($normalized === []) {
                continue;
            }

            $sets[$domain] = $normalized;
        }

        if ($sets === []) {
            return ['independent' => false, 'same_owner' => false, 'known' => false, 'distinct_owners' => 0];
        }

        $distinct = [];

        foreach ($sets as $set) {
            $distinct[implode('|', $set)] = true;
        }

        $ownerCount = count($distinct);
        $comparable = count($sets) >= 2;

        return [
            'independent' => $ownerCount > 1,
            'same_owner' => $comparable && $ownerCount === 1,
            'known' => true,
            'distinct_owners' => $ownerCount,
        ];
    }

    /**
     * @param  array<int, string>  $servers
     * @return array<int, string>
     */
    private function normalize(array $servers): array
    {
        $clean = [];

        foreach ($servers as $server) {
            $server = strtolower(trim((string) $server));

            if ($server === '') {
                continue;
            }

            $clean[] = rtrim($server, '.');
        }

        $clean = array_values(array_unique($clean));
        sort($clean);

        return $clean;
    }
}
