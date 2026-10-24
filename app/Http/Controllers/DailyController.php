<?php

namespace App\Http\Controllers;

use App\Services\CandidateRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DailyController extends Controller
{
    public function __construct(private readonly CandidateRepository $candidates) {}

    public function index(Request $request): View
    {
        $date = $this->date($request);
        $shortOnly = $request->boolean('short');
        $endingsOnly = $request->boolean('endings');

        return view('daily', [
            'date' => $date,
            'shortOnly' => $shortOnly,
            'endingsOnly' => $endingsOnly,
            'data' => $this->dataFor($date, $shortOnly, $endingsOnly),
            'dates' => $this->candidates->candidateDates(60),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->dataFor(
            $this->date($request),
            $request->boolean('short'),
            $request->boolean('endings'),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function dataFor(string $date, bool $shortOnly = false, bool $endingsOnly = false): array
    {
        $counts = $this->candidates->countsForDate($date);

        return [
            'date' => $date,
            'short_only' => $shortOnly,
            'endings_only' => $endingsOnly,
            'counts' => [
                'removed' => $counts['removed'] ?? 0,
                'added' => $counts['added'] ?? 0,
                'candidates' => $counts['candidates'] ?? 0,
            ],
            'removed_names' => $this->candidates->removedNames($date),
            'added_names' => $this->candidates->addedNames($date),
            'candidates' => $this->candidates->forDate($date, 500, $shortOnly, $endingsOnly),
        ];
    }

    private function date(Request $request): string
    {
        $date = (string) $request->query('date', now()->format('Y-m-d'));

        $parsed = Carbon::parse($date);

        return $parsed->format('Y-m-d');
    }
}
