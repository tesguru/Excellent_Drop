<?php

namespace App\Http\Controllers;

use App\Models\JobRun;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class JobsController extends Controller
{
    public function index(): View
    {
        return view('jobs.index', ['runs' => $this->runs()]);
    }

    public function show(string $runId): View
    {
        return view('jobs.show', [
            'runId' => $runId,
            'steps' => $this->steps($runId),
        ]);
    }

    public function data(): JsonResponse
    {
        return response()->json(['runs' => $this->runs()]);
    }

    public function showData(string $runId): JsonResponse
    {
        return response()->json([
            'run_id' => $runId,
            'steps' => $this->steps($runId),
        ]);
    }

    /**
     * Every run with its steps, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function runs(): array
    {
        $runs = JobRun::query()
            ->orderByDesc('queued_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $grouped = [];

        foreach ($runs as $run) {
            $runId = (string) $run->run_id;

            if (! isset($grouped[$runId])) {
                $grouped[$runId] = [
                    'run_id' => $runId,
                    'started_at' => $run->queued_at?->toIso8601String(),
                    'finished_at' => $run->finished_at?->toIso8601String(),
                    'status' => 'running',
                    'steps' => [],
                    'total_rows' => 0,
                ];
            }

            $grouped[$runId]['steps'][] = $this->step($run);
            $grouped[$runId]['total_rows'] += (int) ($run->rows ?? 0);

            if ($run->finished_at !== null) {
                $grouped[$runId]['finished_at'] = $run->finished_at->toIso8601String();
            }
        }

        foreach ($grouped as $runId => $run) {
            $statuses = array_column($run['steps'], 'status');

            $grouped[$runId]['status'] = match (true) {
                in_array('failed', $statuses, true) => 'failed',
                in_array('running', $statuses, true) => 'running',
                in_array('queued', $statuses, true) => 'queued',
                default => 'success',
            };

            $grouped[$runId]['step_count'] = count($run['steps']);

            $error = null;

            foreach ($run['steps'] as $step) {
                if (! empty($step['error'])) {
                    $error = $step['error'];

                    break;
                }
            }

            $grouped[$runId]['error'] = $error;
        }

        return array_values($grouped);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function steps(string $runId): array
    {
        return JobRun::query()
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get()
            ->map(fn (JobRun $run) => $this->step($run))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function step(JobRun $run): array
    {
        return [
            'id' => $run->id,
            'run_id' => $run->run_id,
            'step' => $run->step,
            'tld' => $run->tld,
            'status' => $run->status,
            'attempts' => $run->attempts,
            'queued_at' => $run->queued_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration' => $run->durationInSeconds(),
            'rows' => $run->rows,
            'bytes_done' => $run->bytes_done,
            'bytes_total' => $run->bytes_total,
            'message' => $run->message,
            'error' => $run->error,
        ];
    }
}
