<?php

namespace App\Console\Commands;

use App\Services\JobFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RunStep extends Command
{
    protected $signature = 'zones:step
        {step : One of download, extract, load, diff, candidates, cleanup}
        {--tld= : Limit the step to a single TLD}
        {--run= : Reuse an existing run id}';

    protected $description = 'Run a single step of the zone pipeline';

    public function handle(JobFactory $factory): int
    {
        $step = (string) $this->argument('step');
        $tld = $this->option('tld');
        $tld = is_string($tld) && $tld !== '' ? $tld : null;

        if (! in_array($step, JobFactory::STEPS, true)) {
            $this->error('Unknown step: '.$step);
            $this->line('Available steps: '.implode(', ', JobFactory::STEPS));

            return self::FAILURE;
        }

        $runId = (string) ($this->option('run') ?: Str::uuid());

        $job = $factory->make($step, $runId, $tld);

        if ($job === null) {
            $this->error("Step '{$step}' needs a TLD.");

            return self::FAILURE;
        }

        dispatch($job)->onQueue((string) config('zones.queue'));

        $this->info("Queued step '{$step}'".($tld !== null ? " for {$tld}" : '').". Run id: {$runId}");

        return self::SUCCESS;
    }
}
