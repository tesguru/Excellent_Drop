<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'job_runs';

    protected $fillable = [
        'run_id',
        'step',
        'tld',
        'status',
        'attempts',
        'queued_at',
        'started_at',
        'finished_at',
        'rows',
        'bytes_done',
        'bytes_total',
        'message',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'rows' => 'integer',
            'bytes_done' => 'integer',
            'bytes_total' => 'integer',
        ];
    }

    public function scopeForRun($query, string $runId)
    {
        return $query->where('run_id', $runId);
    }

    public function durationInSeconds(): ?float
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return round($this->started_at->floatDiffInSeconds($this->finished_at), 1);
    }
}
