<?php

namespace App\Http\Controllers;

use App\Services\CandidateRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DailyController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly CandidateRepository $candidates) {}

    public function index(Request $request): View
    {
        $date = $this->date($request);
        $shortOnly = $request->boolean('short');
        $endingsOnly = $request->boolean('endings');
        $term = $this->term($request);

        return view('daily', [
            'date' => $date,
            'shortOnly' => $shortOnly,
            'endingsOnly' => $endingsOnly,
            'data' => $this->dataFor(
                $date,
                $shortOnly,
                $endingsOnly,
                $term,
                $this->page($request),
                $this->variation($request, 'min_variations'),
                $this->variation($request, 'max_variations'),
                $this->variation($request, 'exact_variations'),
            ),
            'dates' => $this->candidates->candidateDates(60),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->dataFor(
            $this->date($request),
            $request->boolean('short'),
            $request->boolean('endings'),
            $this->term($request),
            $this->page($request),
            $this->variation($request, 'min_variations'),
            $this->variation($request, 'max_variations'),
            $this->variation($request, 'exact_variations'),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function dataFor(
        string $date,
        bool $shortOnly,
        bool $endingsOnly,
        string $term,
        int $page,
        ?int $minVariations = null,
        ?int $maxVariations = null,
        ?int $exactVariations = null,
    ): array {
        $counts = $this->candidates->countsForDate($date);

        $result = $this->candidates->pageForDate(
            $date,
            $term,
            self::PER_PAGE,
            $page,
            $shortOnly,
            $endingsOnly,
            $minVariations,
            $maxVariations,
            $exactVariations,
        );

        return [
            'date' => $date,
            'short_only' => $shortOnly,
            'endings_only' => $endingsOnly,
            'query' => $term,
            'min_variations' => $minVariations,
            'max_variations' => $maxVariations,
            'exact_variations' => $exactVariations,
            'counts' => [
                'removed' => $counts['removed'] ?? 0,
                'added' => $counts['added'] ?? 0,
                'candidates' => $counts['candidates'] ?? 0,
            ],
            'pagination' => [
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'pages' => $result['pages'],
                'has_more' => $result['page'] < $result['pages'],
            ],
            'removed_names' => $this->candidates->removedNames($date),
            'added_names' => $this->candidates->addedNames($date),
            'candidates' => $result['candidates'],
        ];
    }

    private function term(Request $request): string
    {
        $term = $request->query('q', '');

        return is_string($term) ? trim($term) : '';
    }

    private function page(Request $request): int
    {
        $page = $request->query('page', 1);

        return max(1, is_scalar($page) ? (int) $page : 1);
    }

    private function variation(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        if (! is_scalar($value)) {
            return null;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT);

        return $number === false ? null : max(0, $number);
    }

    private function date(Request $request): string
    {
        $date = (string) $request->query('date', now()->format('Y-m-d'));

        $parsed = Carbon::parse($date);

        return $parsed->format('Y-m-d');
    }
}
