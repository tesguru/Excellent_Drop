@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $entries = $entries ?? [];
    $statuses = $statuses ?? [];
    $pagination = $pagination ?? ['page' => 1, 'per_page' => 25, 'total' => count($entries), 'pages' => 1];
    $query = $query ?? '';

    $page = (int) ($pagination['page'] ?? 1);
    $pages = max(1, (int) ($pagination['pages'] ?? 1));
    $total = (int) ($pagination['total'] ?? count($entries));
    $perPage = max(1, (int) ($pagination['per_page'] ?? 25));
    $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $to = min($page * $perPage, $total);

    $signalLabels = [
        'other_tld' => 'other TLD',
        'hyphen' => 'hyphen',
        'starts_with' => 'starts with',
        'ends_with' => 'ends with',
        'plural' => 'plural',
        'misspelling' => 'misspelling',
        'contains' => 'contains',
    ];

    $statusStyles = [
        'watching' => 'background: rgb(var(--glow) / 0.14); color: var(--accent)',
        'dropped' => 'background: rgb(16 185 129 / 0.14); color: rgb(5 150 105)',
        'restored' => 'background: rgb(14 165 233 / 0.14); color: rgb(2 132 199)',
        'ignored' => 'background: var(--surface-2); color: var(--muted)',
    ];
@endphp

<div class="card overflow-hidden">
    <div class="border-b px-4 py-3" style="border-color: var(--border)">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="display text-sm font-bold">Watched names</h2>
                <span class="chip muted" style="background: var(--surface-2)">{{ number_format($total) }}</span>

                @if ($query !== '')
                    <span class="chip" style="background: rgb(var(--glow) / 0.14); color: var(--accent)">
                        &ldquo;{{ $query }}&rdquo;
                    </span>
                @endif
            </div>
        </div>

        <form method="GET" action="{{ url('/watchlist') }}" class="mt-3 flex flex-wrap items-center gap-2">
            <div class="relative min-w-[12rem] flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 muted"
                     fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Zm5.5-1.5L21 21" />
                </svg>
                <input id="watchlist-q" name="q" type="search" value="{{ $query }}" autocomplete="off"
                       aria-label="Search watchlist by domain or note"
                       placeholder="Search by domain or note..." class="field pl-9">
            </div>

            <button type="submit" class="btn btn-primary">Search</button>

            @if ($query !== '')
                <a href="{{ request()->fullUrlWithQuery(['q' => null, 'page' => null]) }}" class="btn btn-ghost">
                    Clear
                </a>
            @endif
        </form>
    </div>

    @if (count($entries) === 0)
        <div class="px-4 py-12 text-center">
            <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl"
                  style="background: rgb(var(--glow) / 0.12); color: var(--accent)">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5c-4 0-7 3-8.5 7.5C5 16.5 8 19.5 12 19.5s7-3 8.5-7.5C19 7.5 16 4.5 12 4.5Zm0 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
                </svg>
            </span>
            <p class="mt-4 text-sm font-semibold">
                {{ $query !== '' ? 'No watched names found' : 'The watchlist is empty' }}
            </p>
            <p class="mt-1 text-xs muted">
                @if ($query !== '')
                    Nothing matches &ldquo;{{ $query }}&rdquo;.
                @else
                    Add a domain above to start tracking it.
                @endif
            </p>

            @if ($query !== '')
                <a href="{{ request()->fullUrlWithQuery(['q' => null, 'page' => null]) }}" class="btn btn-ghost mt-4 text-xs">
                    Reset search
                </a>
            @endif
        </div>
    @else
        <div class="divide-y" style="border-color: var(--border)">
            @foreach ($entries as $entry)
                @php
                    $candidate = $entry['candidate'];
                    $variations = array_values((array) ($candidate['weaker_found'] ?? []));
                    $variationCount = count($variations);
                    $notesCount = (int) ($entry['notes_count'] ?? 0);
                    $modalId = 'watchlist-entry-'.$entry['id'];
                    $statusStyle = $statusStyles[$entry['status']] ?? $statusStyles['ignored'];
                @endphp

                <div data-watch-entry data-domain="{{ $entry['domain'] }}">
                    <div class="flex flex-wrap items-center gap-3 px-4 py-3 transition"
                         onmouseover="this.style.background='rgb(var(--glow) / 0.03)'"
                         onmouseout="this.style.background='transparent'">
                        <button type="button" data-modal-open="{{ $modalId }}" aria-haspopup="dialog"
                                class="group w-full min-w-0 text-left sm:w-auto sm:flex-1">
                            <span class="flex items-center gap-2">
                                <span class="truncate font-mono text-sm font-semibold group-hover:underline" style="color: var(--text)">
                                    {{ $entry['domain'] }}
                                </span>
                                <svg class="h-3.5 w-3.5 shrink-0 opacity-50 transition group-hover:opacity-100"
                                     fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" />
                                </svg>
                            </span>
                            <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] muted">
                                <span>Added {{ Carbon::parse($entry['created_at'])->format('Y-m-d') }}</span>
                                @if (! empty($candidate['estimated_drop_date']))
                                    <span>&middot;</span>
                                    <span>estimated drop {{ $candidate['estimated_drop_date'] }}</span>
                                @endif
                            </span>
                        </button>

                        <div class="flex flex-wrap items-center gap-2">
                            <span class="chip font-semibold" data-status-chip style="{{ $statusStyle }}">
                                {{ ucfirst($entry['status']) }}
                            </span>

                            @if ($candidate)
                                <span class="chip bg-gradient-to-br from-sky-400 to-cyan-500 font-bold text-[color:var(--accent-ink)]">
                                    {{ number_format((float) $candidate['score'], 1) }}
                                </span>

                                @if (! empty($candidate['is_short']))
                                    <span class="chip" style="background: rgb(var(--glow) / 0.14); color: var(--accent)"
                                          title="Name has {{ \App\Services\Candidates\WeakerSearcher::SHORT_NAME_MAX_LENGTH }} or fewer characters">
                                        short
                                    </span>
                                @endif
                            @endif

                            @if ($notesCount > 0)
                                <span class="chip muted" style="background: var(--surface-2)">
                                    {{ $notesCount }} {{ Str::plural('note', $notesCount) }}
                                </span>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-2 sm:ml-auto">
                            <button type="button" data-modal-open="{{ $modalId }}" aria-haspopup="dialog"
                                    class="chip border border-[color:var(--border)] muted transition hover:text-[color:var(--text)]">
                                {{ $variationCount }} {{ Str::plural('variation', $variationCount) }}
                            </button>

                            @include('partials.rdap-action', ['domain' => $entry['domain']])

                            <button type="button"
                                    class="watch-remove chip border px-2.5 py-1 text-xs transition hover:text-[color:var(--text)]"
                                    style="border-color: var(--border); color: rgb(225 29 72)"
                                    aria-label="Remove {{ $entry['domain'] }} from the watchlist">
                                Remove
                            </button>
                        </div>
                    </div>

                    <dialog id="{{ $modalId }}" class="modal" data-modal aria-labelledby="{{ $modalId }}-title">
                        <div class="flex items-start justify-between gap-4 border-b px-5 py-4" style="border-color: var(--border)">
                            <div class="min-w-0">
                                <p class="label">Watched name</p>
                                <h2 id="{{ $modalId }}-title" class="display mt-1 truncate text-xl font-extrabold">
                                    {{ $entry['domain'] }}
                                </h2>

                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <span class="chip font-semibold" style="{{ $statusStyle }}">
                                        {{ ucfirst($entry['status']) }}
                                    </span>

                                    @if ($candidate)
                                        <span class="chip bg-gradient-to-br from-sky-400 to-cyan-500 font-bold text-[color:var(--accent-ink)]">
                                            {{ number_format((float) $candidate['score'], 1) }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <button type="button" data-modal-close class="btn btn-ghost shrink-0 p-2" aria-label="Close">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6 6 18" />
                                </svg>
                            </button>
                        </div>

                        <div class="max-h-[calc(100dvh-9rem)] space-y-5 overflow-y-auto overscroll-contain px-5 py-4">
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div class="rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2)">
                                    <p class="label">Added</p>
                                    <p class="mt-1 font-mono text-sm font-semibold">
                                        {{ Carbon::parse($entry['created_at'])->format('Y-m-d') }}
                                    </p>
                                </div>

                                <div class="rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2)">
                                    <p class="label">Estimated drop</p>
                                    <p class="mt-1 font-mono text-sm font-semibold">
                                        {{ $candidate['estimated_drop_date'] ?? '—' }}
                                    </p>
                                </div>

                                <div class="rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2)">
                                    <p class="label">Weaker versions</p>
                                    <p class="mt-1 font-mono text-sm font-semibold">{{ number_format($variationCount) }}</p>
                                </div>
                            </div>

                            <section>
                                <div class="flex items-center justify-between gap-2">
                                    <h3 class="display text-sm font-bold">Weaker variations</h3>
                                    <span class="chip muted" style="background: var(--surface-2)">{{ number_format($variationCount) }}</span>
                                </div>

                                @if ($variationCount === 0)
                                    <p class="mt-2 rounded-xl border px-3 py-4 text-sm muted"
                                       style="border-color: var(--border); background: var(--surface-2)">
                                        No weaker versions were recorded for this name.
                                    </p>
                                @else
                                    <ul class="mt-2 space-y-2">
                                        @foreach ($variations as $index => $variation)
                                            @php
                                                $signals = array_filter(explode(',', (string) ($candidate['weaker_types'][$index] ?? '')));
                                            @endphp

                                            <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border px-3 py-2.5"
                                                style="border-color: var(--border); background: var(--surface-2)">
                                                <div class="min-w-0">
                                                    <p class="truncate font-mono text-sm font-semibold">{{ $variation }}</p>

                                                    @if (count($signals) > 0)
                                                        <div class="mt-1 flex flex-wrap gap-1">
                                                            @foreach ($signals as $signal)
                                                                <span class="rounded px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide muted"
                                                                      style="background: var(--surface)">
                                                                    {{ $signalLabels[$signal] ?? Str::headline($signal) }}
                                                                </span>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>

                                                @include('partials.rdap-action', ['domain' => $variation])
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </section>

                            @include('partials.rdap-panel', ['domain' => $entry['domain']])

                            <section>
                                <div class="flex items-center justify-between gap-2">
                                    <h3 class="display text-sm font-bold">Notes</h3>
                                    <span class="chip muted" style="background: var(--surface-2)">{{ number_format($notesCount) }}</span>
                                </div>

                                @if ($notesCount === 0)
                                    <p class="mt-2 rounded-xl border px-3 py-4 text-sm muted"
                                       style="border-color: var(--border); background: var(--surface-2)">
                                        No notes yet.
                                    </p>
                                @else
                                    <ul class="mt-2 space-y-2">
                                        @foreach ($entry['notes_list'] as $note)
                                            <li class="rounded-xl border px-3 py-2.5"
                                                style="border-color: var(--border); background: var(--surface-2)">
                                                <p class="text-[11px] muted">
                                                    {{ Carbon::parse($note['created_at'])->format('Y-m-d H:i') }}
                                                </p>
                                                <p class="mt-1 text-sm">{{ $note['body'] }}</p>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                <form class="mt-3 flex flex-wrap gap-2" data-note-form>
                                    @csrf
                                    <input type="text" name="body" placeholder="Add a note" required
                                           class="field min-w-[10rem] flex-1">
                                    <button type="submit" class="btn btn-ghost">Save note</button>
                                </form>
                            </section>

                            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border px-3 py-3"
                                 style="border-color: var(--border); background: var(--surface-2)">
                                <div class="flex items-center gap-2">
                                    <label class="label" for="{{ $modalId }}-status">Status</label>
                                    <select id="{{ $modalId }}-status"
                                            class="watch-status field w-auto cursor-pointer px-2 py-1 text-xs">
                                        @foreach ($statuses as $status)
                                            <option value="{{ $status }}" @selected($status === $entry['status'])>
                                                {{ ucfirst($status) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <button type="button" class="watch-remove btn btn-ghost px-2.5 py-1 text-xs"
                                        style="color: rgb(225 29 72)">
                                    Remove from watchlist
                                </button>
                            </div>
                        </div>
                    </dialog>
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3" style="border-color: var(--border)">
            <p class="text-xs muted">
                Showing
                <span class="font-semibold tabular-nums" style="color: var(--text)">{{ number_format($from) }}&ndash;{{ number_format($to) }}</span>
                of <span class="font-semibold tabular-nums" style="color: var(--text)">{{ number_format($total) }}</span>
            </p>

            <div class="flex flex-wrap items-center gap-2">
                @include('partials.page-mover', ['page' => $page, 'pages' => $pages])

                @if ($page > 1)
                    <a href="{{ request()->fullUrlWithQuery(['page' => $page - 1]) }}"
                       class="btn btn-ghost px-3 py-1.5 text-xs">Previous</a>
                @else
                    <span class="btn btn-ghost cursor-not-allowed px-3 py-1.5 text-xs opacity-40">Previous</span>
                @endif

                <span class="chip muted" style="background: var(--surface-2)">
                    Page {{ number_format($page) }} of {{ number_format($pages) }}
                </span>

                @if ($page < $pages)
                    <a href="{{ request()->fullUrlWithQuery(['page' => $page + 1]) }}"
                       class="btn btn-ghost px-3 py-1.5 text-xs">Next</a>
                @else
                    <span class="btn btn-ghost cursor-not-allowed px-3 py-1.5 text-xs opacity-40">Next</span>
                @endif
            </div>
        </div>
    @endif
</div>
