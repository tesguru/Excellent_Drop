<?php

namespace App\Console\Commands;

use App\Models\JobRun;
use App\Services\JobFactory;
use Illuminate\Console\Command;

class RetryJobs extends Command
{
    protected $signature = 'zones:retry
        {id? : The job_runs row to retry}
        {--all : Retry every failed row of the latest runs}
        {--run= : Limit the retry to a single run id}';

    protected $description = 'Retry failed pipeline steps';

    public function handle(JobFactory $factory): int
    {
        $id = $this->argument('id');
        $runId = $this->option('run');

        $query = JobRun::query()->where('status', JobRun::STATUS_FAILED);

        if (is_string($runId) && $runId !== '') {
            $query->where('run_id', $runId);
        }

        if (is_string($id) && $id !== '') {
            $query->whereKey((int) $id);
        } elseif (! $this->option('all')) {
            $this->error('Give a job id or use --all.');

            return self::FAILURE;
        }

        $rows = $query->orderByDesc('id')->limit(200)->get();

        if ($rows->isEmpty()) {
            $this->info('No failed jobs to retry.');

            return self::SUCCESS;
        }

        $queued = 0;

        foreach ($rows as $row) {
            $job = $factory->make((string) $row->step, (string) $row->run_id, $row->tld);

            if ($job === null) {
                continue;
            }

            dispatch($job)->onQueue((string) config('zones.queue'));

            $queued++;
        }

        $this->info("Queued {$queued} job(s) for retry.");

        return self::SUCCESS;
    }
}
