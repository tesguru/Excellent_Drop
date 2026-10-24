@php
    $results = $data['results'];
    $error = $data['error'];
    $query = $data['query'];
@endphp

@if ($error !== null)
    <div class="card mt-6 p-6 text-sm" style="color: rgb(251 113 133)">
        {{ $error }}
    </div>
@elseif ($query === '')
    <div class="card mt-6 grid place-items-center p-12 text-center">
        <div class="mx-auto max-w-sm">
            <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl" style="background: rgb(var(--glow) / 0.12)">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" style="color: var(--accent)">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Zm5.5-1.5L21 21" />
                </svg>
            </span>
            <p class="mt-4 text-sm muted">Type a name to search the latest zone snapshot.</p>
        </div>
    </div>
@else
    <div class="card mt-6 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3" style="border-color: var(--border)">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="display text-sm font-bold">Zone results</h2>
                <span class="chip muted" style="background: var(--surface-2)">{{ count($results) }}</span>
                @if ($data['latest'] !== null)
                    <span class="text-xs muted">latest snapshot {{ $data['latest'] }}</span>
                @endif
            </div>
            <span class="text-xs muted">Page {{ $data['page'] }}</span>
        </div>

        @if (count($results) === 0)
            <p class="px-4 py-10 text-center text-sm muted">No names match this search.</p>
        @else
            <div class="divide-y" style="border-color: var(--border)">
                @foreach ($results as $result)
                    <div class="flex flex-wrap items-center gap-2 px-4 py-3">
                        <a href="{{ url('/search?'.http_build_query(['q' => $result['name']])) }}"
                           class="font-mono text-sm font-semibold hover:underline" style="color: var(--text)">
                            {{ $result['domain'] }}
                        </a>
                        @include('partials.lookup-link', ['domain' => $result['domain']])

                        @if ($result['removed_on'] !== null)
                            <span class="chip" style="background: rgb(244 63 94 / 0.12); color: rgb(251 113 133)">
                                Removed {{ $result['removed_on'] }}
                            </span>
                        @endif

                        @if ($result['added_on'] !== null)
                            <span class="chip" style="background: rgb(16 185 129 / 0.13); color: rgb(52 211 153)">
                                Added {{ $result['added_on'] }}
                            </span>
                        @endif

                        @if ($result['removed_on'] === null && $result['added_on'] === null)
                            <span class="chip muted" style="background: var(--surface-2)">
                                No event in {{ \App\Services\Zone\ZoneSearch::EVENT_WINDOW_DAYS }} days
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>

            @if ($data['page'] > 1 || $data['has_more'])
                <div class="flex items-center justify-between border-t px-4 py-3" style="border-color: var(--border)">
                    @if ($data['page'] > 1)
                        <a href="{{ request()->fullUrlWithQuery(['page' => $data['page'] - 1]) }}"
                           class="text-sm font-semibold" style="color: var(--accent)">Previous</a>
                    @else
                        <span></span>
                    @endif

                    @if ($data['has_more'])
                        <a href="{{ request()->fullUrlWithQuery(['page' => $data['page'] + 1]) }}"
                           class="text-sm font-semibold" style="color: var(--accent)">Next</a>
                    @endif
                </div>
            @endif
        @endif
    </div>
@endif
