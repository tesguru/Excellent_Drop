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

    public function __construct(private readonly CandidateRepository $candidates) {}

    public function index(): View
    {
        return view('watchlist', [
            'entries' => $this->entries(),
            'statuses' => self::STATUSES,
        ]);
    }

    public function data(): JsonResponse
    {
        return response()->json(['entries' => $this->entries()]);
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
     * @return array<int, array<string, mixed>>
     */
    private function entries(): array
    {
        return Watchlist::query()
            ->with('notesList')
            ->orderBy('domain')
            ->get()
            ->map(fn (Watchlist $entry) => $this->present($entry))
            ->all();
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
            'notes_list' => $entry->notesList
                ->map(fn (Note $note) => [
                    'id' => $note->id,
                    'body' => $note->body,
                    'created_at' => $note->created_at?->toIso8601String(),
                ])
                ->all(),
            'candidate' => $candidate === null ? null : [
                'event_date' => $candidate['event_date'],
                'score' => $candidate['score'],
                'weaker_found' => $candidate['weaker_found'],
                'weaker_types' => $candidate['weaker_types'],
                'estimated_drop_date' => $candidate['estimated_drop_date'],
            ],
        ];
    }
}
