<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Overview') &middot; DropWatch</title>
    <script>
        // Set the theme before the first paint so there is no flash.
        (function () {
            var stored = localStorage.getItem('dropwatch-theme');
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (stored === 'dark' || (!stored && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        ink: {
                            50: '#f6f7f9',
                            100: '#eceef2',
                            200: '#d5d9e2',
                            300: '#b0b8c9',
                            400: '#8590a9',
                            500: '#66738f',
                            600: '#515c76',
                            700: '#424a60',
                            800: '#394051',
                            900: '#333947',
                            950: '#22262f',
                        },
                    },
                },
            },
        };
    </script>
    <style>
        [x-cloak] { display: none !important; }
        body { font-feature-settings: "cv02", "cv03", "cv04", "tnum"; }
    </style>
</head>
<body class="h-full bg-ink-50 text-ink-900 antialiased dark:bg-ink-950 dark:text-ink-100">
<div class="flex min-h-full">

    <aside id="sidebar"
           class="fixed inset-y-0 left-0 z-30 w-64 -translate-x-full border-r border-ink-200 bg-white transition-transform lg:translate-x-0 dark:border-ink-800 dark:bg-ink-900">
        <div class="flex h-16 items-center gap-2 border-b border-ink-200 px-5 dark:border-ink-800">
            <span class="grid h-9 w-9 place-items-center rounded-lg bg-ink-900 text-sm font-bold text-white dark:bg-white dark:text-ink-900">DW</span>
            <div>
                <p class="text-sm font-semibold leading-tight">DropWatch</p>
                <p class="text-xs leading-tight text-ink-500 dark:text-ink-400">Zone drop monitor</p>
            </div>
        </div>

        <nav class="flex-1 space-y-1 overflow-y-auto p-3 text-sm">
            @php
                $nav = [
                    ['route' => 'overview', 'label' => 'Overview', 'path' => '/'],
                    ['route' => 'daily', 'label' => 'Daily list', 'path' => '/daily'],
                    ['route' => 'search', 'label' => 'Search', 'path' => '/search'],
                    ['route' => 'watchlist', 'label' => 'Watchlist', 'path' => '/watchlist'],
                    ['route' => 'jobs', 'label' => 'Jobs', 'path' => '/jobs'],
                    ['route' => 'zones', 'label' => 'Zones', 'path' => '/zones'],
                ];
            @endphp

            @foreach ($nav as $item)
                <a href="{{ url($item['path']) }}"
                   class="flex items-center gap-3 rounded-lg px-3 py-2 font-medium text-ink-600 transition hover:bg-ink-100 hover:text-ink-900 dark:text-ink-300 dark:hover:bg-ink-800 dark:hover:text-white {{ request()->routeIs($item['route']) ? 'bg-ink-900 text-white hover:bg-ink-900 hover:text-white dark:bg-white dark:text-ink-900 dark:hover:bg-white dark:hover:text-ink-900' : '' }}">
                    <span class="grid h-5 w-5 place-items-center text-xs font-bold opacity-70">{{ substr($item['label'], 0, 1) }}</span>
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="border-t border-ink-200 p-4 text-xs dark:border-ink-800">
            <p class="font-medium text-ink-700 dark:text-ink-200">{{ auth()->user()?->name ?? 'Guest' }}</p>
            <p class="mt-0.5 truncate text-ink-500 dark:text-ink-400">{{ auth()->user()?->email }}</p>
            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf
                <button type="submit" class="w-full rounded-lg border border-ink-200 px-3 py-1.5 font-medium text-ink-600 transition hover:bg-ink-100 dark:border-ink-700 dark:text-ink-300 dark:hover:bg-ink-800">
                    Sign out
                </button>
            </form>
        </div>
    </aside>

    <div id="overlay" class="fixed inset-0 z-20 hidden bg-ink-950/50 lg:hidden"></div>

    <div class="flex min-w-0 flex-1 flex-col lg:pl-64">
        <header class="sticky top-0 z-10 flex h-16 items-center gap-4 border-b border-ink-200 bg-ink-50/90 px-4 backdrop-blur dark:border-ink-800 dark:bg-ink-950/90 sm:px-6">
            <button id="menu-toggle" type="button" class="rounded-lg border border-ink-200 p-2 text-ink-600 lg:hidden dark:border-ink-700 dark:text-ink-300">
                <span class="sr-only">Toggle navigation</span>
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            <div class="min-w-0 flex-1">
                <h1 class="truncate text-base font-semibold">@yield('title', 'Overview')</h1>
                @hasSection('subtitle')
                    <p class="truncate text-xs text-ink-500 dark:text-ink-400">@yield('subtitle')</p>
                @endif
            </div>

            <div id="worker-status" class="hidden items-center gap-2 rounded-full border border-ink-200 bg-white px-3 py-1.5 text-xs font-medium dark:border-ink-800 dark:bg-ink-900 sm:flex">
                <span data-worker-dot class="h-2 w-2 rounded-full bg-ink-300"></span>
                <span data-worker-label>Worker</span>
            </div>

            <button id="theme-toggle" type="button" class="rounded-lg border border-ink-200 p-2 text-ink-600 dark:border-ink-700 dark:text-ink-300">
                <span class="sr-only">Toggle dark mode</span>
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                </svg>
            </button>
        </header>

        <main class="flex-1 space-y-6 p-4 sm:p-6">
            @if (session('status'))
                <div class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('error'))
                <div class="rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200">
                    {{ session('error') }}
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

<script src="{{ asset('js/app.js') }}"></script>
@stack('scripts')
</body>
</html>
