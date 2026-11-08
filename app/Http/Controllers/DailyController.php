<?php

namespace App\Http\Controllers;

use App\Services\CandidateRepository;
use Carbon\Exceptions\InvalidFormatException;
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
        return $this->respond($request, 'daily', url('/daily'), now()->format('Y-m-d'));
    }

    public function overview(Request $request): View
    {
        return $this->respond($request, 'overview', url('/'), null);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->dataFor($request, $this->date($request, now()->format('Y-m-d'))));
    }

    public function overviewData(Request $request): JsonResponse
    {
        return response()->json($this->dataFor($request, $this->date($request, $this->latestDate())));
    }

    /**
     * An AJAX request gets the list partial only, so the browser can swap one
     * region instead of reloading the document.
     */
    private function respond(Request $request, string $view, string $action, ?string $defaultDate): View
    {
        $dates = $this->candidates->candidateDates(60);
        $date = $this->date($request, $defaultDate ?? ($dates[0] ?? now()->format('Y-m-d')));
        $data = $this->dataFor($request, $date);

        return view($request->ajax() ? 'partials.daily-candidates' : $view, [
            'action' => $action,
            'date' => $date,
            'dates' => $dates,
            'shortOnly' => $data['short_only'],
            'endingsOnly' => $data['endings_only'],
            'query' => $data['query'],
            'minVariations' => $data['min_variations'],
            'maxVariations' => $data['max_variations'],
            'exactVariations' => $data['exact_variations'],
            'candidates' => $data['candidates'],
            'pagination' => $data['pagination'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dataFor(Request $request, string $date): array
    {
        $term = $this->term($request);
        $shortOnly = $request->boolean('short');
        $endingsOnly = $request->boolean('endings');
        $minVariations = $this->variation($request, 'min_variations');
        $maxVariations = $this->variation($request, 'max_variations');
        $exactVariations = $this->variation($request, 'exact_variations');

        $result = $this->candidates->pageForDate(
            $date,
            $term,
            self::PER_PAGE,
            $this->page($request),
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
            'pagination' => [
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'pages' => $result['pages'],
                'has_more' => $result['page'] < $result['pages'],
            ],
            'candidates' => $result['candidates'],
        ];
    }

    private function latestDate(): string
    {
        return $this->candidates->candidateDates(1)[0] ?? now()->format('Y-m-d');
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

    private function date(Request $request, string $default): string
    {
        $value = $request->query('date');

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (InvalidFormatException) {
            return $default;
        }
    }
}
