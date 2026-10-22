<?php

namespace App\Console\Commands;

use App\Models\JobRun;
use Illuminate\Console\Command;

class ClearStuckJobs extends Command
{
    protected $signature = 'zones:clear-stuck {--hours=2 : Mark rows running for longer than this as failed}';

    protected $description = 'Mark job runs that are stuck in the running state as failed';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');

        $cutoff = now()->subHours($hours);

        $rows = JobRun::where('status', JobRun::STATUS_RUNNING)
            ->where('started_at', '<', $cutoff)
            ->get();

        foreach ($rows as $row) {
            $row->forceFill([
                'status' => JobRun::STATUS_FAILED,
                'finished_at' => now(),
                'error' => 'Marked as failed: the job was still running after '.$hours.' hour(s).',
            ])->save();
        }

        $this->info('Marked '.$rows->count().' stuck job run(s) as failed.');

        return self::SUCCESS;
    }
}
