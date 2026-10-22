<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark light">
    <title>@yield('title', 'Overview') &middot; DropWatch</title>
    <script>
        // Dark is the default. Light is opt-in and remembered.
        (function () {
            var stored = localStorage.getItem('dropwatch-theme');
            if (stored !== 'light') {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full antialiased" style="background: var(--bg); color: var(--text)">
<div class="flex min-h-full">

    <aside id="sidebar"
           class="fixed inset-y-0 left-0 z-30 flex w-64 -translate-x-full flex-col border-r transition-transform lg:translate-x-0"
           style="background: var(--sidebar); border-color: var(--border)">
        <div class="flex h-16 items-center gap-3 border-b px-5" style="border-color: var(--border)">
            <span class="relative grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-sky-400 to-cyan-500 font-display text-sm font-extrabold text-[color:var(--accent-ink)] shadow-lg shadow-sky-500/30">DW</span>
            <div>
                <p class="display text-[15px] font-bold leading-tight">DropWatch</p>
                <p class="text-[11px] leading-tight muted">Zone drop monitor</p>
            </div>
        </div>

        <nav class="flex-1 space-y-1 overflow-y-auto p-3 text-sm">
            @php
                $nav = [
                    ['route' => 'overview', 'label' => 'Overview', 'path' => '/', 'icon' => 'M4 13h6V4H4v9Zm10 7h6v-9h-6v9ZM4 20h6v-4H4v4Zm10-11h6V4h-6v5Z'],
                    ['route' => 'daily', 'label' => 'Daily list', 'path' => '/daily', 'icon' => 'M7 3v3m10-3v3M4 9h16M5 6h14a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Zm3 7h3v3H8v-3Z'],
                    ['route' => 'search', 'label' => 'Search', 'path' => '/search', 'icon' => 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Zm5.5-1.5L21 21'],
                    ['route' => 'watchlist', 'label' => 'Watchlist', 'path' => '/watchlist', 'icon' => 'M12 4.5c-4 0-7 3-8.5 7.5C5 16.5 8 19.5 12 19.5s7-3 8.5-7.5C19 7.5 16 4.5 12 4.5Zm0 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z'],
                    ['route' => 'jobs', 'label' => 'Jobs', 'path' => '/jobs', 'icon' => 'M4 6h16M4 12h10M4 18h13'],
                    ['route' => 'zones', 'label' => 'Zones', 'path' => '/zones', 'icon' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm0 0c2.5 2.4 4 5.6 4 9s-1.5 6.6-4 9m0-18c-2.5 2.4-4 5.6-4 9s1.5 6.6 4 9M3.5 9h17M3.5 15h17'],
                ];
            @endphp

            @foreach ($nav as $item)
                @php $active = request()->routeIs($item['route']); @endphp
                <a href="{{ url($item['path']) }}"
                   class="group relative flex items-center gap-3 rounded-xl px-3 py-2 font-medium transition {{ $active ? '' : 'muted hover:text-[color:var(--text)]' }}"
                   @if ($active)
                       style="background: rgb(var(--glow) / 0.12); color: var(--text)"
                   @else
                       onmouseover="this.style.background='rgb(var(--glow) / 0.06)'"
                       onmouseout="this.style.background='transparent'"
                   @endif>
                    @if ($active)
                        <span class="absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full" style="background: var(--accent)"></span>
                    @endif
                    <svg class="h-[18px] w-[18px] shrink-0 {{ $active ? '' : 'opacity-70' }}" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" style="{{ $active ? 'color: var(--accent)' : '' }}">
                        <path d="{{ $item['icon'] }}" />
                    </svg>
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="border-t p-4 text-xs" style="border-color: var(--border)">
            <div class="flex items-center gap-3">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-gradient-to-br from-sky-400 to-cyan-500 font-display text-xs font-bold text-[color:var(--accent-ink)]">
                    {{ strtoupper(substr(auth()->user()?->name ?? 'G', 0, 1)) }}
                </span>
                <div class="min-w-0">
                    <p class="truncate font-semibold" style="color: var(--text)">{{ auth()->user()?->name ?? 'Guest' }}</p>
                    <p class="truncate muted">{{ auth()->user()?->email }}</p>
                </div>
            </div>
            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf
                <button type="submit" class="btn btn-ghost w-full py-1.5 text-xs">
                    Sign out
                </button>
            </form>
        </div>
    </aside>

    <div id="overlay" class="fixed inset-0 z-20 hidden bg-black/60 backdrop-blur-sm lg:hidden"></div>

    <div class="flex min-w-0 flex-1 flex-col lg:pl-64">
        <header class="sticky top-0 z-10 flex h-16 items-center gap-4 border-b px-4 backdrop-blur-xl sm:px-6"
                style="border-color: var(--border); background: color-mix(in srgb, var(--bg) 82%, transparent)">
            <button id="menu-toggle" type="button" class="btn btn-ghost p-2 lg:hidden">
                <span class="sr-only">Toggle navigation</span>
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            <div class="min-w-0 flex-1">
                <h1 class="display truncate text-lg font-bold">@yield('title', 'Overview')</h1>
                @hasSection('subtitle')
                    <p class="truncate text-xs muted">@yield('subtitle')</p>
                @endif
            </div>

            <div id="worker-status" class="hidden items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium sm:flex"
                 style="border-color: var(--border); background: var(--surface)">
                <span data-worker-dot class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-sky-400 opacity-60"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-sky-400"></span>
                </span>
                <span data-worker-label class="muted">Worker</span>
            </div>

            <button id="theme-toggle" type="button" class="btn btn-ghost p-2" title="Toggle theme">
                <span class="sr-only">Toggle theme</span>
                <svg data-theme-icon="dark" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 0 1 8.646 3.646 9 9 0 1 0 20.354 15.354Z" />
                </svg>
                <svg data-theme-icon="light" class="hidden h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2m0 14v2m9-9h-2M5 12H3m14.95-5.95-1.414 1.414M7.464 16.536 6.05 17.95m11.9 0-1.414-1.414M7.464 7.464 6.05 6.05M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z" />
                </svg>
            </button>
        </header>

        <main class="rise flex-1 space-y-6 p-4 sm:p-6">
            @if (session('status'))
                <div class="card border-emerald-400/40 px-4 py-3 text-sm" style="background: rgb(16 185 129 / 0.1); color: rgb(52 211 153)">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('error'))
                <div class="card border-rose-400/40 px-4 py-3 text-sm" style="background: rgb(244 63 94 / 0.1); color: rgb(251 113 133)">
                    {{ session('error') }}
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

@stack('scripts')
</body>
</html>
