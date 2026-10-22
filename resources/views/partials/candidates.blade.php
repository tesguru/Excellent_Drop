@php
    use Illuminate\Support\Str;

    $shortFilterUrl = $shortFilterUrl ?? null;
    $shortFilterActive = $shortFilterActive ?? false;

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
        'pending delete' => 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
        'redemption period' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
        'hold' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
        'active' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    ];
@endphp

<div class="overflow-hidden rounded-xl border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-900">
    <div class="flex items-center justify-between border-b border-ink-200 px-4 py-3 dark:border-ink-800">
        <h2 class="text-sm font-semibold">Candidates</h2>

        <div class="flex items-center gap-2">
            @if ($shortFilterUrl !== null)
                <a href="{{ $shortFilterUrl }}"
                   class="rounded-lg border px-2.5 py-0.5 text-xs font-medium transition {{ $shortFilterActive ? 'border-sky-600 bg-sky-600 text-white dark:border-sky-400 dark:bg-sky-400 dark:text-ink-950' : 'border-ink-300 text-ink-600 hover:bg-ink-100 dark:border-ink-700 dark:text-ink-300 dark:hover:bg-ink-800' }}">
                    {{ $shortFilterActive ? 'Show all' : 'Is short' }}
                </a>
            @endif

            <span class="rounded-full bg-ink-100 px-2.5 py-0.5 text-xs font-medium text-ink-600 dark:bg-ink-800 dark:text-ink-300">
                {{ count($candidates) }}
            </span>
        </div>
    </div>

    @if (count($candidates) === 0)
        <p class="px-4 py-8 text-center text-sm text-ink-500 dark:text-ink-400">
            No candidates yet. Run the pipeline to populate this list.
        </p>
    @else
        <div class="divide-y divide-ink-200 dark:divide-ink-800">
            @foreach ($candidates as $candidate)
                <div class="px-4 py-4" data-candidate-row>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ url('/search?q=' . urlencode($candidate['name'])) }}"
                                   class="font-mono text-sm font-semibold text-ink-900 hover:underline dark:text-white">
                                    {{ $candidate['domain'] }}
                                </a>
                                <span class="rounded-md bg-ink-900 px-2 py-0.5 text-xs font-semibold text-white dark:bg-white dark:text-ink-900">
                                    score {{ number_format((float) $candidate['score'], 1) }}
                                </span>
                                @if (! empty($candidate['is_short']))
                                    <span class="rounded-md bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-700 dark:bg-sky-950 dark:text-sky-300"
                                          title="Name has {{ \App\Services\Candidates\WeakerSearcher::SHORT_NAME_MAX_LENGTH }} or fewer characters">
                                        short
                                    </span>
                                @endif
                                @if (! empty($candidate['restored']))
                                    <span class="rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                                        restored
                                    </span>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-ink-500 dark:text-ink-400">
                                Removed {{ $candidate['event_date'] }}
                                @if (! empty($candidate['predicted_drop_date']))
                                    &middot; predicted drop {{ $candidate['predicted_drop_date'] }}
                                @endif
                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            @if (! empty($candidate['rdap_status']))
                                <span class="rounded-md px-2 py-0.5 text-xs font-medium {{ $statusStyles[strtolower($candidate['rdap_status'])] ?? 'bg-ink-100 text-ink-700 dark:bg-ink-800 dark:text-ink-300' }}">
                                    {{ $candidate['rdap_status'] }}
                                </span>
                            @else
                                <span class="rounded-md bg-ink-100 px-2 py-0.5 text-xs font-medium text-ink-500 dark:bg-ink-800 dark:text-ink-400">
                                    RDAP unknown
                                </span>
                            @endif

                            <button type="button"
                                    class="watch-toggle rounded-lg border px-2.5 py-1 text-xs font-medium transition {{ ! empty($candidate['watchlisted']) ? 'border-ink-900 bg-ink-900 text-white dark:border-white dark:bg-white dark:text-ink-900' : 'border-ink-300 text-ink-600 hover:bg-ink-100 dark:border-ink-700 dark:text-ink-300 dark:hover:bg-ink-800' }}"
                                    data-domain="{{ $candidate['domain'] }}"
                                    data-watched="{{ ! empty($candidate['watchlisted']) ? '1' : '0' }}">
                                {{ ! empty($candidate['watchlisted']) ? 'Watching' : 'Watch' }}
                            </button>
                        </div>
                    </div>

                    @if (count($candidate['weaker_found']) > 0)
                        <ul class="mt-3 flex flex-wrap gap-2">
                            @foreach ($candidate['weaker_found'] as $index => $domain)
                                <li class="rounded-lg border border-ink-200 bg-ink-50 px-2.5 py-1 font-mono text-xs dark:border-ink-800 dark:bg-ink-950">
                                    {{ $domain }}
                                    @php
                                        $signals = array_filter(explode(',', (string) ($candidate['weaker_types'][$index] ?? '')));
                                    @endphp
                                    @foreach ($signals as $signal)
                                        <span class="ml-1 rounded bg-white px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-ink-500 dark:bg-ink-800 dark:text-ink-400">
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
