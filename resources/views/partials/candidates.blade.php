@php
    use Illuminate\Support\Str;

    $shortFilterUrl = $shortFilterUrl ?? null;
    $shortFilterActive = $shortFilterActive ?? false;
    $endingsFilterUrl = $endingsFilterUrl ?? null;
    $endingsFilterActive = $endingsFilterActive ?? false;

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
    <div class="flex items-center justify-between border-b px-4 py-3" style="border-color: var(--border)">
        <h2 class="display text-sm font-bold">Candidates</h2>

        <div class="flex items-center gap-2">
            @if ($shortFilterUrl !== null)
                <a href="{{ $shortFilterUrl }}"
                   class="chip border transition {{ $shortFilterActive ? 'border-transparent text-[color:var(--accent-ink)]' : 'muted border-transparent hover:text-[color:var(--text)]' }}"
                   @if ($shortFilterActive) style="background: var(--accent)" @else style="background: rgb(var(--glow) / 0.1)" @endif>
                    {{ $shortFilterActive ? 'Show all' : 'Is short' }}
                </a>
            @endif

            @if ($endingsFilterUrl !== null)
                <a href="{{ $endingsFilterUrl }}"
                   class="chip border transition {{ $endingsFilterActive ? 'border-transparent text-[color:var(--accent-ink)]' : 'muted border-transparent hover:text-[color:var(--text)]' }}"
                   @if ($endingsFilterActive) style="background: var(--accent)" @else style="background: rgb(var(--glow) / 0.1)" @endif>
                    {{ $endingsFilterActive ? 'Show all' : '2+ endings' }}
                </a>
            @endif

            <span data-rdap-selection-count class="text-xs muted">0 selected</span>

            <button type="button" data-rdap-check-selected class="btn btn-ghost px-2.5 py-1 text-xs" disabled>
                Check selected
            </button>

            <span class="chip muted" style="background: var(--surface-2)">{{ count($candidates) }}</span>
        </div>
    </div>

    <p data-rdap-selection-message class="px-4 pt-2 text-xs muted"></p>

    @if (count($candidates) === 0)
        <p class="px-4 py-10 text-center text-sm muted">
            No candidates yet. Run the pipeline to populate this list.
        </p>
    @else
        <div class="divide-y" style="border-color: var(--border)">
            @foreach ($candidates as $candidate)
                <div class="px-4 py-4 transition" style="border-color: var(--border)"
                     onmouseover="this.style.background='rgb(var(--glow) / 0.03)'"
                     onmouseout="this.style.background='transparent'">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex min-w-0 items-start gap-2.5">
                            <input type="checkbox" data-rdap-select value="{{ $candidate['domain'] }}"
                                   aria-label="Select {{ $candidate['domain'] }} for a status check"
                                   class="mt-1 h-3.5 w-3.5 shrink-0 cursor-pointer accent-sky-500">

                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ url('/search?q=' . urlencode($candidate['name'])) }}"
                                       class="font-mono text-sm font-semibold hover:underline" style="color: var(--text)">
                                        {{ $candidate['domain'] }}
                                    </a>
                                    @include('partials.rdap-action', ['domain' => $candidate['domain']])
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
                                <p class="mt-1.5 text-xs muted">
                                    Removed {{ $candidate['event_date'] }}
                                    @if (! empty($candidate['estimated_drop_date']))
                                        &middot; estimated drop {{ $candidate['estimated_drop_date'] }}
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            @php $watching = ! empty($candidate['watchlisted']); @endphp
                            <button type="button"
                                    class="watch-toggle chip border px-2.5 py-1 transition {{ $watching ? 'border-transparent' : 'border-[color:var(--border)] muted hover:text-[color:var(--text)]' }}"
                                    @if ($watching) style="background: rgb(var(--glow) / 0.14); color: var(--accent)" @endif
                                    data-domain="{{ $candidate['domain'] }}"
                                    data-watched="{{ $watching ? '1' : '0' }}">
                                {{ $watching ? 'Watching' : 'Watch' }}
                            </button>
                        </div>
                    </div>

                    @if (count($candidate['weaker_found']) > 0)
                        <ul class="mt-3 flex flex-wrap gap-2">
                            @foreach ($candidate['weaker_found'] as $index => $domain)
                                <li class="rounded-lg border px-2.5 py-1 font-mono text-xs"
                                    style="border-color: var(--border); background: var(--surface-2)">
                                    {{ $domain }}
                                    @include('partials.rdap-action', ['domain' => $domain])
                                    @php
                                        $signals = array_filter(explode(',', (string) ($candidate['weaker_types'][$index] ?? '')));
                                    @endphp
                                    @foreach ($signals as $signal)
                                        <span class="ml-1 rounded px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide muted"
                                              style="background: var(--surface)">
                                            {{ $signalLabels[$signal] ?? Str::headline($signal) }}
                                        </span>
                                    @endforeach
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
