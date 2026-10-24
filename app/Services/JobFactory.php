<?php

namespace App\Services;

use App\Jobs\BuildComCandidates;
use App\Jobs\Cleanup;
use App\Jobs\DiffZones;
use App\Jobs\DownloadZones;
use App\Jobs\ExtractNames;
use App\Jobs\LoadSnapshot;

/**
 * Rebuilds a queued job from a job_runs row so a failed step can be retried.
 */
class JobFactory
{
    public const STEPS = [
        'download',
        'extract',
        'load',
        'diff',
        'candidates',
        'cleanup',
    ];

    public function make(string $step, string $runId, ?string $tld = null): ?object
    {
        return match ($step) {
            'download' => new DownloadZones($runId),
            'extract' => $tld !== null ? new ExtractNames($runId, $tld) : null,
            'load' => $tld !== null ? new LoadSnapshot($runId, $tld) : null,
            'diff' => new DiffZones($runId),
            'candidates' => new BuildComCandidates($runId),
            'cleanup' => new Cleanup($runId),
            default => null,
        };
    }
}
