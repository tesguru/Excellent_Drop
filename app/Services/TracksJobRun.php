<?php

namespace App\Services;

use App\Models\JobRun;
use Throwable;

/**
 * Shared plumbing that keeps a job_runs row in sync with the job that owns
 * it. The row is always closed out, even when the job blows up.
 */
trait TracksJobRun
{
    protected ?JobRun $jobRun = null;

    protected function beginRun(string $step, ?string $tld = null): void
    {
        $this->jobRun = $this->newRun($step, $tld);
    }

    /**
     * Create an additional tracking row (a step may own several rows, for
     * example one row per TLD).
     */
    protected function newRun(string $step, ?string $tld = null): JobRun
    {
        return app(JobRunTracker::class)->start(
            $this->runIdentifier(),
            $step,
            $tld,
            $this->attempts(),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function updateRun(array $attributes): void
    {
        if ($this->jobRun === null) {
            return;
        }

        app(JobRunTracker::class)->update($this->jobRun, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function updateRow(JobRun $row, array $attributes): void
    {
        app(JobRunTracker::class)->update($row, $attributes);
    }

    protected function finishRun(?int $rows = null, ?string $message = null): void
    {
        if ($this->jobRun === null) {
            return;
        }

        app(JobRunTracker::class)->succeed($this->jobRun, $rows, $message);
        $this->jobRun = null;
    }

    protected function succeedRow(JobRun $row, ?int $rows = null, ?string $message = null): void
    {
        app(JobRunTracker::class)->succeed($row, $rows, $message);
    }

    protected function failRun(Throwable $exception, ?string $message = null): void
    {
        if ($this->jobRun === null) {
            return;
        }

        app(JobRunTracker::class)->fail($this->jobRun, $exception->getMessage(), $message);
        $this->jobRun = null;
    }

    protected function failRow(JobRun $row, Throwable $exception, ?string $message = null): void
    {
        app(JobRunTracker::class)->fail($row, $exception->getMessage(), $message);
    }

    protected function skipRun(string $message): void
    {
        if ($this->jobRun === null) {
            return;
        }

        app(JobRunTracker::class)->skip($this->jobRun, $message);
        $this->jobRun = null;
    }

    protected function skipRow(JobRun $row, string $message): void
    {
        app(JobRunTracker::class)->skip($row, $message);
    }

    abstract public function runIdentifier(): string;
}
