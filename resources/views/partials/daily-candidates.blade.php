@php
    use Illuminate\Support\Str;

    $candidates = $candidates ?? [];
    $pagination = $pagination ?? ['page' => 1, 'per_page' => 25, 'total' => count($candidates), 'pages' => 1];
    $query = $query ?? '';
    $shortOnly = $shortOnly ?? false;
    $endingsOnly = $endingsOnly ?? false;
    $date = $date ?? '';

    $page = (int) ($pagination['page'] ?? 1);
    $pages = max(1, (int) ($pagination['pages'] ?? 1));
    $total = (int) ($pagination['total'] ?? count($candidates));
    $perPage = max(1, (int) ($pagination['per_page'] ?? 25));
    $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $to = min($page * $perPage, $total);

    $shortFilterUrl = request()->fullUrlWithQuery(['short' => $shortOnly ? null : 1, 'page' => null]);
    $endingsFilterUrl = request()->fullUrlWithQuery(['endings' => $endingsOnly ? null : 1, 'page' => null]);

    $signalLabels = [
        'other_tld' => 'other TLD',
        'hyphen' => 'hyphen',
        'starts_with' => 'starts with',
        'ends_with' => 'ends with',
        'plural' => 'plural',
        'misspelling' => 'misspelling',
        'contains' => 'contains',
    ];
@endphp

<div class="card overflow-hidden">
    <div class="border-b px-4 py-3" style="border-color: var(--border)">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="display text-sm font-bold">Candidates</h2>
                <span class="chip muted" style="background: var(--surface-2)">{{ number_format($total) }}</span>

                @if ($query !== '')
                    <span class="chip" style="background: rgb(var(--glow) / 0.14); color: var(--accent)">
                        &ldquo;{{ $query }}&rdquo;
                    </span>
                @endif
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ $shortFilterUrl }}"
                   class="chip border transition {{ $shortOnly ? 'border-transparent text-[color:var(--accent-ink)]' : 'muted border-transparent hover:text-[color:var(--text)]' }}"
                   @if ($shortOnly) style="background: var(--accent)" @else style="background: rgb(var(--glow) / 0.1)" @endif>
                    {{ $shortOnly ? 'Show all' : 'Is short' }}
                </a>

                <a href="{{ $endingsFilterUrl }}"
                   class="chip border transition {{ $endingsOnly ? 'border-transparent text-[color:var(--accent-ink)]' : 'muted border-transparent hover:text-[color:var(--text)]' }}"
                   @if ($endingsOnly) style="background: var(--accent)" @else style="background: rgb(var(--glow) / 0.1)" @endif>
                    {{ $endingsOnly ? 'Show all' : '2+ endings' }}
                </a>
            </div>
        </div>

        <form method="GET" action="{{ url('/daily') }}" class="mt-3 flex flex-wrap items-center gap-2">
            <input type="hidden" name="date" value="{{ $date }}">
            @if ($shortOnly)
                <input type="hidden" name="short" value="1">
            @endif
            @if ($endingsOnly)
                <input type="hidden" name="endings" value="1">
            @endif

            <div class="relative min-w-[12rem] flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 muted"
                     fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Zm5.5-1.5L21 21" />
                </svg>
                <input id="daily-q" name="q" type="search" value="{{ $query }}" autocomplete="off"
                       aria-label="Search candidates by keyword"
                       placeholder="Search candidates by keyword..." class="field pl-9">
            </div>

            <button type="submit" class="btn btn-primary">Search</button>

            @if ($query !== '')
                <a href="{{ request()->fullUrlWithQuery(['q' => null, 'page' => null]) }}" class="btn btn-ghost">
                    Clear
                </a>
            @endif
        </form>
    </div>

    @if (count($candidates) === 0)
        <div class="px-4 py-12 text-center">
            <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl"
                  style="background: rgb(var(--glow) / 0.12); color: var(--accent)">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Zm5.5-1.5L21 21" />
                </svg>
            </span>
            <p class="mt-4 text-sm font-semibold">No candidates found</p>
            <p class="mt-1 text-xs muted">
                @if ($query !== '')
                    Nothing on {{ $date }} matches &ldquo;{{ $query }}&rdquo;.
                @else
                    No candidates for this date with these filters.
                @endif
            </p>

            @if ($query !== '' || $shortOnly || $endingsOnly)
                <a href="{{ request()->fullUrlWithQuery(['q' => null, 'short' => null, 'endings' => null, 'page' => null]) }}"
                   class="btn btn-ghost mt-4 text-xs">
                    Reset filters
                </a>
            @endif
        </div>
    @else
        <div class="divide-y" style="border-color: var(--border)">
            @foreach ($candidates as $candidate)
                @php
                    $variationCount = count((array) ($candidate['weaker_found'] ?? []));
                    $modalId = 'daily-candidate-'.$loop->index;
                    $watching = ! empty($candidate['watchlisted']);
                @endphp

                <div class="flex flex-wrap items-center gap-3 px-4 py-3 transition"
                     onmouseover="this.style.background='rgb(var(--glow) / 0.03)'"
                     onmouseout="this.style.background='transparent'">
                    <button type="button" data-modal-open="{{ $modalId }}" aria-haspopup="dialog"
                            class="group w-full min-w-0 text-left sm:w-auto sm:flex-1">
                        <span class="flex items-center gap-2">
                            <span class="truncate font-mono text-sm font-semibold group-hover:underline" style="color: var(--text)">
                                {{ $candidate['domain'] }}
                            </span>
                            <svg class="h-3.5 w-3.5 shrink-0 opacity-50 transition group-hover:opacity-100"
                                 fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" />
                            </svg>
                        </span>
                        <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] muted">
                            <span>Removed {{ $candidate['event_date'] }}</span>
                            @if (! empty($candidate['estimated_drop_date']))
                                <span>&middot;</span>
                                <span>estimated drop {{ $candidate['estimated_drop_date'] }}</span>
                            @endif
                        </span>
                    </button>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="chip bg-gradient-to-br from-sky-400 to-cyan-500 font-bold text-[color:var(--accent-ink)]">
                            {{ number_format((float) $candidate['score'], 1) }}
                        </span>

                        @if (! empty($candidate['is_short']))
                            <span class="chip" style="background: rgb(var(--glow) / 0.14); color: var(--accent)"
                                  title="Name has {{ \App\Services\Candidates\WeakerSearcher::SHORT_NAME_MAX_LENGTH }} or fewer characters">
                                short
                            </span>
                        @endif

                        @php $exactTldCount = (int) ($candidate['exact_tld_count'] ?? 0); @endphp
                        <span class="chip muted" style="background: var(--surface-2)"
                              title="The exact name is registered on {{ $exactTldCount }} other TLD{{ $exactTldCount === 1 ? '' : 's' }}">
                            {{ $exactTldCount }} ending{{ $exactTldCount === 1 ? '' : 's' }}
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 sm:ml-auto">
                        <button type="button" data-modal-open="{{ $modalId }}" aria-haspopup="dialog"
                                class="chip border border-[color:var(--border)] muted transition hover:text-[color:var(--text)]">
                            {{ $variationCount }} {{ Str::plural('variation', $variationCount) }}
                        </button>

                        <button type="button"
                                class="watch-toggle chip border px-2.5 py-1 transition {{ $watching ? 'border-transparent' : 'border-[color:var(--border)] muted hover:text-[color:var(--text)]' }}"
                                @if ($watching) style="background: rgb(var(--glow) / 0.14); color: var(--accent)" @endif
                                data-domain="{{ $candidate['domain'] }}"
                                data-watched="{{ $watching ? '1' : '0' }}">
                            {{ $watching ? 'Watching' : 'Watch' }}
                        </button>

                        <a href="{{ \App\Support\LookupLink::for($candidate['domain']) }}"
                           target="_blank" rel="noopener noreferrer"
                           title="Look up {{ $candidate['domain'] }} in RDAP"
                           class="chip border border-[color:var(--border)] muted transition hover:text-[color:var(--text)]">
                            RDAP
                            <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 5h5v5m0-5-8 8M19 14v4a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h4" />
                            </svg>
                        </a>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3" style="border-color: var(--border)">
            <p class="text-xs muted">
                Showing
                <span class="font-semibold tabular-nums" style="color: var(--text)">{{ number_format($from) }}&ndash;{{ number_format($to) }}</span>
                of <span class="font-semibold tabular-nums" style="color: var(--text)">{{ number_format($total) }}</span>
            </p>

            <div class="flex items-center gap-2">
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

@if (count($candidates) > 0)
    @foreach ($candidates as $candidate)
        @php
            $variations = array_values((array) ($candidate['weaker_found'] ?? []));
            $variationCount = count($variations);
            $modalId = 'daily-candidate-'.$loop->index;
            $watching = ! empty($candidate['watchlisted']);
        @endphp

        <dialog id="{{ $modalId }}" class="modal" data-modal aria-labelledby="{{ $modalId }}-title">
            <div class="flex items-start justify-between gap-4 border-b px-5 py-4" style="border-color: var(--border)">
                <div class="min-w-0">
                    <p class="label">Candidate</p>
                    <h2 id="{{ $modalId }}-title" class="display mt-1 truncate text-xl font-extrabold">
                        {{ $candidate['domain'] }}
                    </h2>

                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span class="chip bg-gradient-to-br from-sky-400 to-cyan-500 font-bold text-[color:var(--accent-ink)]">
                            {{ number_format((float) $candidate['score'], 1) }}
                        </span>

                        @if (! empty($candidate['is_short']))
                            <span class="chip" style="background: rgb(var(--glow) / 0.14); color: var(--accent)">short</span>
                        @endif

                        <span class="chip muted" style="background: var(--surface-2)">
                            {{ (int) ($candidate['exact_tld_count'] ?? 0) }} ending{{ (int) ($candidate['exact_tld_count'] ?? 0) === 1 ? '' : 's' }}
                        </span>
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
                        <p class="label">Removed</p>
                        <p class="mt-1 font-mono text-sm font-semibold">{{ $candidate['event_date'] }}</p>
                    </div>

                    <div class="rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2)">
                        <p class="label">Estimated drop</p>
                        <p class="mt-1 font-mono text-sm font-semibold">{{ $candidate['estimated_drop_date'] ?? '—' }}</p>
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

                                    <a href="{{ \App\Support\LookupLink::for($variation) }}"
                                       target="_blank" rel="noopener noreferrer"
                                       title="Look up {{ $variation }} in RDAP"
                                       class="chip border border-[color:var(--border)] muted transition hover:text-[color:var(--text)]">
                                        RDAP
                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 5h5v5m0-5-8 8M19 14v4a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h4" />
                                        </svg>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                @include('partials.rdap-panel', ['domain' => $candidate['domain']])

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border px-3 py-3"
                     style="border-color: var(--border); background: var(--surface-2)">
                    <div>
                        <p class="text-sm font-semibold">Track this name</p>
                        <p class="mt-0.5 text-xs muted">Add {{ $candidate['domain'] }} to your watchlist for follow-up.</p>
                    </div>

                    <button type="button"
                            class="watch-toggle chip border px-2.5 py-1 transition {{ $watching ? 'border-transparent' : 'border-[color:var(--border)] muted hover:text-[color:var(--text)]' }}"
                            @if ($watching) style="background: rgb(var(--glow) / 0.14); color: var(--accent)" @endif
                            data-domain="{{ $candidate['domain'] }}"
                            data-watched="{{ $watching ? '1' : '0' }}">
                        {{ $watching ? 'Watching' : 'Watch' }}
                    </button>
                </div>
            </div>
        </dialog>
    @endforeach
@endif
