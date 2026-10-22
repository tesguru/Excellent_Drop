<?php

namespace App\Http\Controllers;

use App\Models\ZoneTld;
use App\Services\CandidateRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class ZonesController extends Controller
{
    public function __construct(private readonly CandidateRepository $candidates) {}

    public function index(): View
    {
        return view('zones', [
            'zones' => $this->zones(),
            'warningDays' => (int) config('zones.zone_access.warning_days', 14),
        ]);
    }

    public function data(): JsonResponse
    {
        return response()->json([
            'zones' => $this->zones(),
            'warning_days' => (int) config('zones.zone_access.warning_days', 14),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function zones(): array
    {
        $today = now()->format('Y-m-d');

        return ZoneTld::query()
            ->orderBy('tld')
            ->get()
            ->map(function (ZoneTld $zone) use ($today) {
                return [
                    'tld' => $zone->tld,
                    'status' => $zone->status,
                    'expiry_at' => $zone->expiry_at?->format('Y-m-d'),
                    'days_until_expiry' => $zone->daysUntilExpiry(),
                    'last_download_at' => $zone->last_download_at?->toIso8601String(),
                    'last_modified' => $zone->last_modified?->format('Y-m-d H:i'),
                    'file_size' => $zone->file_size,
                    'rows' => $zone->rows,
                    'message' => $zone->message,
                    'has_snapshot' => in_array($zone->tld, $this->candidates->tldsForDate($today), true),
                ];
            })
            ->all();
    }
}
