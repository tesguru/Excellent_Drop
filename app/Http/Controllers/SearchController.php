<?php

namespace App\Http\Controllers;

use App\Services\CandidateRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function __construct(private readonly CandidateRepository $candidates) {}

    public function index(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));

        return view('search', [
            'query' => $query,
            'results' => $query === '' ? [] : $this->candidates->search($query),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        return response()->json([
            'query' => $query,
            'results' => $query === '' ? [] : $this->candidates->search($query),
        ]);
    }
}
