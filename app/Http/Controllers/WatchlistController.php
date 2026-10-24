<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Models\Watchlist;
use App\Services\CandidateRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WatchlistController extends Controller
{
    public const STATUSES = ['watching', 'dropped', 'restored', 'ignored'];

    private const PER_PAGE = 25;

    public function __construct(private readonly CandidateRepository $candidates) {}

    public function index(Request $request): View
    {
        $term = $this->term($request);
        $result = $this->entries($term, $this->page($request));

        return view('watchlist', [
            'entries' => $result['entries'],
            'statuses' => self::STATUSES,
            'query' => $term,
            'pagination' => $result['pagination'],
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $term = $this->term($request);
        $result = $this->entries($term, $this->page($request));

        return response()->json([
            'query' => $term,
            'pagination' => $result['pagination'],
            'entries' => $result['entries'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'status' => ['nullable', 'string', 'in:'.implode(',', self::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $domain = strtolower(trim((string) $data['domain']));

        $entry = Watchlist::updateOrCreate(
            ['domain' => $domain],
            [
                'status' => $data['status'] ?? 'watching',
                'notes' => $data['notes'] ?? null,
            ],
        );

        return response()->json(['entry' => $this->present($entry)], 201);
    }

    public function update(Request $request, string $domain): JsonResponse
    {
        $entry = Watchlist::where('domain', $domain)->first();

        if ($entry === null) {
            return response()->json(['error' => 'Not on the watchlist.'], 404);
        }

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', self::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $entry->fill(array_filter($data, fn ($value) => $value !== null))->save();

        return response()->json(['entry' => $this->present($entry->refresh())]);
    }

    public function destroy(string $domain): JsonResponse
    {
        Watchlist::where('domain', $domain)->delete();
        Note::where('domain', $domain)->delete();

        return response()->json(['deleted' => $domain]);
    }

    public function storeNote(Request $request, string $domain): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $note = Note::create([
            'domain' => $domain,
            'body' => (string) $data['body'],
        ]);

        return response()->json(['note' => [
            'id' => $note->id,
            'domain' => $note->domain,
            'body' => $note->body,
            'created_at' => $note->created_at?->toIso8601String(),
        ]], 201);
    }

    /**
     * @return array{entries: array<int, array<string, mixed>>, pagination: array<string, int|bool>}
     */
    private function entries(string $term = '', int $page = 1): array
    {
        $query = Watchlist::query()->with('notesList');

        foreach ($this->terms($term) as $part) {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $part).'%';

            $query->where(function ($nested) use ($pattern) {
                $nested->whereRaw("LOWER(domain) LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(notes, '')) LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereHas(
                        'notesList',
                        fn ($notes) => $notes->whereRaw("LOWER(body) LIKE ? ESCAPE '\\'", [$pattern]),
                    );
            });
        }

        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);

        $entries = $query
            ->orderBy('domain')
            ->forPage($page, self::PER_PAGE)
            ->get()
            ->map(fn (Watchlist $entry) => $this->present($entry))
            ->all();

        return [
            'entries' => $entries,
            'pagination' => [
                'page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'pages' => $pages,
                'has_more' => $page < $pages,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function terms(string $term): array
    {
        $terms = array_filter(
            preg_split('/\s+/', strtolower(trim($term))) ?: [],
            fn (string $part) => $part !== '',
        );

        return array_slice(array_values($terms), 0, 5);
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

    /**
     * @return array<string, mixed>
     */
    private function present(Watchlist $entry): array
    {
        $candidate = null;
        $label = (string) preg_replace('/\.[^.]+$/', '', strtolower($entry->domain));

        foreach ($this->candidates->search($label, 50) as $row) {
            if (($row['domain'] ?? null) === $entry->domain) {
                $candidate = $row;

                break;
            }
        }

        return [
            'id' => $entry->id,
            'domain' => $entry->domain,
            'status' => $entry->status,
            'notes' => $entry->notes,
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
            'notes_count' => $entry->notesList->count(),
            'notes_list' => $entry->notesList
                ->map(fn (Note $note) => [
                    'id' => $note->id,
                    'body' => $note->body,
                    'created_at' => $note->created_at?->toIso8601String(),
                ])
                ->all(),
            'candidate' => $candidate === null ? null : [
                'name' => $candidate['name'] ?? $label,
                'event_date' => $candidate['event_date'],
                'score' => $candidate['score'],
                'weaker_found' => $candidate['weaker_found'],
                'weaker_types' => $candidate['weaker_types'],
                'weaker_count' => $candidate['weaker_count'] ?? count((array) $candidate['weaker_found']),
                'tld_count' => $candidate['tld_count'] ?? null,
                'exact_tld_count' => $candidate['exact_tld_count'] ?? null,
                'is_short' => $candidate['is_short'] ?? false,
                'estimated_drop_date' => $candidate['estimated_drop_date'],
            ],
        ];
    }
}
