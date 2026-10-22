<?php

namespace App\Services;

/**
 * Paths used by the zone pipeline.
 */
class ZonePaths
{
    public function dir(): string
    {
        return rtrim((string) config('zones.dir'), '/');
    }

    public function zoneFile(string $tld): string
    {
        return $this->dir().'/'.$tld.'.zone.gz';
    }

    public function namesFile(string $tld): string
    {
        return $this->dir().'/'.$tld.'.names.tsv';
    }

    public function ensureDir(): string
    {
        $dir = $this->dir();

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }
}
