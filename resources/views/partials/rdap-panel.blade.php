@php
    $lookupUrl = \App\Support\LookupLink::for($domain);
    $lookupSource = \App\Support\LookupLink::source($domain);
@endphp

<div class="relative overflow-hidden rounded-2xl border p-4"
     data-rdap data-rdap-mode="panel"
     data-domain="{{ $domain }}" data-url="{{ $lookupUrl }}" data-source="{{ $lookupSource }}"
     style="border-color: rgb(var(--glow) / 0.35); background: linear-gradient(135deg, rgb(var(--glow) / 0.14), rgb(var(--glow) / 0.02) 55%, transparent)">
    <div class="pointer-events-none absolute -right-10 -top-10 h-32 w-32 rounded-full opacity-40 blur-2xl"
         style="background: radial-gradient(circle, rgb(var(--glow) / 0.8), transparent 70%)"></div>

    <div class="relative flex flex-wrap items-start justify-between gap-4">
        <div class="flex min-w-0 items-start gap-3">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl"
                  style="background: rgb(var(--glow) / 0.18); color: var(--accent)">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm0 0c2.5 2.4 4 5.6 4 9s-1.5 6.6-4 9m0-18c-2.5 2.4-4 5.6-4 9s1.5 6.6 4 9M3.5 9h17M3.5 15h17" />
                </svg>
            </span>

            <div class="min-w-0">
                <p class="label">RDAP status</p>
                <p class="mt-1 text-sm font-semibold">
                    Check the registry record for <span class="font-mono">{{ $domain }}</span>
                </p>
                <p class="mt-1 text-xs muted">
                    The check runs in your browser. DropWatch's server never contacts RDAP.
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" data-rdap-check class="btn btn-primary">
                Check status
            </button>

            <a href="{{ $lookupUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-ghost">
                View raw
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 5h5v5m0-5-8 8M19 14v4a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h4" />
                </svg>
            </a>

            <button type="button" class="btn btn-ghost" data-copy="{{ $domain }}">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 8V5a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1h-3M5 8h9a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z" />
                </svg>
                <span data-copy-label>Copy</span>
            </button>
        </div>
    </div>

    <div class="relative mt-4">
        @include('partials.rdap-result')
    </div>
</div>
