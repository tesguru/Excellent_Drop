<?php

namespace App\Http\Controllers;

use App\Services\Zone\ZoneSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function __construct(private readonly ZoneSearch $search) {}

    public function index(Request $request): View
    {
        $data = $this->payload($request);

        return view('search', [
            'data' => $data,
            'tldOptions' => $data['latest'] === null ? [] : $this->search->tlds($data['latest']),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $query = trim((string) $request->query('q', ''));

        $mode = (string) $request->query('mode', 'contains');

        if (! in_array($mode, ZoneSearch::MODES, true)) {
            $mode = 'contains';
        }

        $tlds = array_values(array_unique(array_filter(
            array_map(
                fn ($tld) => strtolower(trim((string) $tld)),
                (array) $request->query('tld', []),
            ),
            fn (string $tld) => preg_match('/^[a-z0-9-]{2,63}$/', $tld) === 1,
        )));

        $minLength = $this->length($request->query('min_length'));
        $maxLength = $this->length($request->query('max_length'));
        $hideDigits = $request->boolean('hide_digits');
        $hideHyphens = $request->boolean('hide_hyphens');
        $page = max(1, (int) $request->query('page', 1));

        $result = $this->search->search([
            'query' => $query,
            'mode' => $mode,
            'tlds' => $tlds,
            'min_length' => $minLength,
            'max_length' => $maxLength,
            'hide_digits' => $hideDigits,
            'hide_hyphens' => $hideHyphens,
            'page' => $page,
        ]);

        return array_merge($result, [
            'query' => $query,
            'mode' => $mode,
            'tlds' => $tlds,
            'min_length' => $minLength,
            'max_length' => $maxLength,
            'hide_digits' => $hideDigits,
            'hide_hyphens' => $hideHyphens,
        ]);
    }

    private function length(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $length = (int) $value;

        return $length >= 1 && $length <= 63 ? $length : null;
    }
}
