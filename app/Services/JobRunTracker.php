<?php

namespace App\Services;

use App\Models\JobRun;

/**
 * Writes the job_runs rows. Every job in the chain reports here.
 */
class JobRunTracker
{
    public function start(string $runId, string $step, ?string $tld = null, int $attempts = 1): JobRun
    {
        return JobRun::create([
            'run_id' => $runId,
            'step' => $step,
            'tld' => $tld,
            'status' => JobRun::STATUS_RUNNING,
            'attempts' => $attempts,
            'queued_at' => now(),
            'started_at' => now(),
            'bytes_done' => 0,
            'bytes_total' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(JobRun $run, array $attributes): void
    {
        $run->forceFill($attributes)->save();
    }

    public function succeed(JobRun $run, ?int $rows = null, ?string $message = null): void
    {
        $run->forceFill([
            'status' => JobRun::STATUS_SUCCESS,
            'finished_at' => now(),
            'rows' => $rows ?? $run->rows,
            'message' => $message ?? $run->message,
        ])->save();
    }

    public function fail(JobRun $run, string $error, ?string $message = null): void
    {
        $run->forceFill([
            'status' => JobRun::STATUS_FAILED,
            'finished_at' => now(),
            'message' => $message ?? $run->message ?? $error,
            'error' => $error,
        ])->save();
    }

    public function skip(JobRun $run, string $message): void
    {
        $run->forceFill([
            'status' => JobRun::STATUS_SKIPPED,
            'finished_at' => now(),
            'message' => $message,
        ])->save();
    }
}
