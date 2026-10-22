<?php

namespace App\Services;

use App\Models\ZoneTld;

/**
 * Keeps track of the zones the account has access to.
 *
 * The list is refreshed from the CZDS API on every run so newly approved
 * zones are picked up automatically.
 */
class ZoneRegistry
{
    /**
     * Record the zones that were visible in the CZDS download links.
     *
     * @param  array<string, string>  $links  tld => url
     */
    public function syncApproved(array $links): void
    {
        foreach (array_keys($links) as $tld) {
            $zone = ZoneTld::firstOrNew(['tld' => $tld]);

            if (! $zone->exists) {
                $zone->status = 'approved';
                $zone->expiry_at = $this->configuredExpiry($tld);
                $zone->save();
            }
        }

        $approved = array_keys($links);

        ZoneTld::whereNotIn('tld', $approved)->get()->each(function (ZoneTld $zone) {
            if ($zone->status !== 'not_approved') {
                $zone->status = 'not_approved';
                $zone->save();
            }
        });
    }

    /**
     * @return array<int, string>
     */
    public function approvedTlds(): array
    {
        return ZoneTld::where('status', '!=', 'not_approved')
            ->orderBy('tld')
            ->pluck('tld')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function tldsForRun(?string $date = null): array
    {
        return ZoneTld::where('status', '!=', 'not_approved')
            ->whereNotNull('last_download_at')
            ->when($date !== null, fn ($query) => $query->whereDate('last_download_at', $date))
            ->orderBy('tld')
            ->pluck('tld')
            ->all();
    }

    public function mark(string $tld, array $attributes): void
    {
        $zone = ZoneTld::firstOrNew(['tld' => $tld]);
        $zone->fill($attributes);

        if (! $zone->exists && $zone->expiry_at === null) {
            $zone->expiry_at = $this->configuredExpiry($tld);
        }

        $zone->save();
    }

    private function configuredExpiry(string $tld): ?string
    {
        $expiry = config('zones.zone_access.expiry.'.$tld);

        return is_string($expiry) && $expiry !== '' ? $expiry : null;
    }
}
