<?php

namespace App\Http\Controllers;

use App\Models\JobRun;
use App\Models\ZoneTld;
use App\Services\CandidateRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class OverviewController extends Controller
{
    public function __construct(private readonly CandidateRepository $candidates) {}

    public function index(Request $request): View
    {
        return view('overview', ['data' => $this->payload($request->boolean('short'))]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->boolean('short')));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(bool $shortOnly = false): array
    {
        $today = now()->format('Y-m-d');
        $counts = $this->candidates->countsForDate($today);

        $week = $this->weekCounts();

        $heartbeat = Cache::get((string) config('zones.heartbeat_cache_key'));
        $staleAfter = (int) config('zones.heartbeat_stale_seconds', 180);

        $workerAlive = false;
        $workerSeenAt = null;

        if (is_string($heartbeat) && $heartbeat !== '') {
            $workerSeenAt = $heartbeat;
            $workerAlive = now()->diffInSeconds(Carbon::parse($heartbeat)) <= $staleAfter;
        }

        $warningDays = (int) config('zones.zone_access.warning_days', 14);

        $zones = ZoneTld::query()
            ->orderBy('tld')
            ->get();

        $expiring = $zones
            ->filter(fn (ZoneTld $zone) => $zone->isExpiringSoon($warningDays))
            ->values()
            ->map(fn (ZoneTld $zone) => [
                'tld' => $zone->tld,
                'expiry_at' => $zone->expiry_at?->format('Y-m-d'),
                'days_until_expiry' => $zone->daysUntilExpiry(),
                'message' => $zone->message,
            ])
            ->all();

        $lastRun = JobRun::query()
            ->orderByDesc('queued_at')
            ->orderByDesc('id')
            ->first();

        return [
            'generated_at' => now()->toIso8601String(),
            'date' => $today,
            'short_only' => $shortOnly,
            'counts' => [
                'removed' => $counts['removed'] ?? 0,
                'added' => $counts['added'] ?? 0,
                'candidates' => $counts['candidates'] ?? 0,
                'week_candidates' => $week['candidates'] ?? 0,
                'week_removed' => $week['removed'] ?? 0,
            ],
            'worker' => [
                'alive' => $workerAlive,
                'last_seen_at' => $workerSeenAt,
                'stale_after_seconds' => $staleAfter,
            ],
            'last_run' => $lastRun === null ? null : [
                'run_id' => $lastRun->run_id,
                'step' => $lastRun->step,
                'tld' => $lastRun->tld,
                'status' => $lastRun->status,
                'message' => $lastRun->message,
                'finished_at' => $lastRun->finished_at?->toIso8601String(),
            ],
            'zone_access' => [
                'warning_days' => $warningDays,
                'expiring' => $expiring,
            ],
            'top_candidates' => $this->candidates->forDate($today, 10, $shortOnly),
            'snapshot_dates' => $this->candidates->candidateDates(30),
        ];
    }

    /**
     * @return array{candidates:int, removed:int}
     */
    private function weekCounts(): array
    {
        $from = now()->subDays(6)->format('Y-m-d');
        $to = now()->format('Y-m-d');

        return [
            'candidates' => count($this->candidates->forRange($from, $to, 5000)),
            'removed' => $this->candidates->removedCount($from, $to),
        ];
    }
}
