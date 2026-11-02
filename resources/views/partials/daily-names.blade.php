@php
    $names = $names ?? [];
    $pagination = $pagination ?? ['page' => 1, 'per_page' => 25, 'total' => count($names), 'pages' => 1];
    $query = $query ?? '';

    $page = (int) ($pagination['page'] ?? 1);
    $pages = max(1, (int) ($pagination['pages'] ?? 1));
    $total = (int) ($pagination['total'] ?? count($names));
    $perPage = max(1, (int) ($pagination['per_page'] ?? 25));
    $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $to = min($page * $perPage, $total);
@endphp

<div class="card overflow-hidden">
    <div class="border-b px-4 py-3" style="border-color: var(--border)">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="display text-sm font-bold">Pending delete candidates</h2>
                <span class="chip muted" style="background: var(--surface-2)">{{ number_format($total) }}</span>

                @if ($query !== '')
                    <span class="chip" style="background: rgb(var(--glow) / 0.14); color: var(--accent)">
                        &ldquo;{{ $query }}&rdquo;
                    </span>
                @endif
            </div>

            <span class="text-xs muted">.com names still registered but heading for deletion. The date does not apply.</span>
        </div>

        <form method="GET" action="{{ url('/daily') }}" class="mt-3 flex flex-wrap items-center gap-2">
            <input type="hidden" name="list" value="pending">

            <div class="relative min-w-[12rem] flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 muted"
                     fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Zm5.5-1.5L21 21" />
                </svg>
                <input name="q" type="search" value="{{ $query }}" autocomplete="off"
                       aria-label="Search pending delete candidates by keyword"
                       placeholder="Search pending delete candidates by keyword..." class="field pl-9">
            </div>

            <button type="submit" class="btn btn-primary">Search</button>

            @if ($query !== '')
                <a href="{{ request()->fullUrlWithQuery(['q' => null, 'page' => null]) }}" class="btn btn-ghost">
                    Clear
                </a>
            @endif
        </form>
    </div>

    @if (count($names) === 0)
        <div class="px-4 py-12 text-center">
            <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl"
                  style="background: rgb(var(--glow) / 0.12); color: var(--accent)">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Zm5.5-1.5L21 21" />
                </svg>
            </span>
            <p class="mt-4 text-sm font-semibold">No names found</p>
            <p class="mt-1 text-xs muted">
                @if ($query !== '')
                    Nothing matches &ldquo;{{ $query }}&rdquo;.
                @else
                    Nothing is pending right now.
                @endif
            </p>
        </div>
    @else
        <div class="divide-y" style="border-color: var(--border)">
            @foreach ($names as $name)
                <div class="flex flex-wrap items-center gap-3 px-4 py-3 transition"
                     onmouseover="this.style.background='rgb(var(--glow) / 0.03)'"
                     onmouseout="this.style.background='transparent'">
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-mono text-sm font-semibold" style="color: var(--text)">
                            {{ $name['domain'] }}
                        </p>
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] muted">
                            @if (! empty($name['drop_utc']))
                                <span>estimated drop {{ $name['drop_utc'] }} UTC</span>
                            @endif

                            @if (! empty($name['registrar']))
                                <span>&middot;</span>
                                <span>{{ $name['registrar'] }}</span>
                            @endif
                        </p>
                    </div>

                    @include('partials.rdap-action', ['domain' => $name['domain']])
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
