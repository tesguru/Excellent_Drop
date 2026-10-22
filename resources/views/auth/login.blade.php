<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title>Sign in &middot; DropWatch</title>
    <script>
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
<div class="relative grid min-h-full lg:grid-cols-2">

    {{-- Brand panel ------------------------------------------------------- --}}
    <aside class="relative hidden overflow-hidden border-r lg:flex lg:flex-col lg:justify-between lg:p-12"
           style="border-color: var(--border); background: var(--sidebar)">
        <div class="aurora pointer-events-none absolute -left-1/3 top-0 h-[42rem] w-[42rem] rounded-full opacity-40 blur-3xl"
             style="background: radial-gradient(circle at center, rgb(56 189 248 / 0.55), transparent 62%)"></div>
        <div class="aurora pointer-events-none absolute -bottom-40 -right-24 h-[34rem] w-[34rem] rounded-full opacity-30 blur-3xl"
             style="background: radial-gradient(circle at center, rgb(34 211 238 / 0.5), transparent 62%); animation-delay: -6s"></div>
        <div class="pointer-events-none absolute inset-0 opacity-[0.04]"
             style="background-image: linear-gradient(var(--text) 1px, transparent 1px), linear-gradient(90deg, var(--text) 1px, transparent 1px); background-size: 44px 44px"></div>

        <div class="relative flex items-center gap-3">
            <span class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-sky-400 to-cyan-500 font-display text-sm font-extrabold text-[color:var(--accent-ink)] shadow-lg shadow-sky-500/40">DW</span>
            <span class="display text-lg font-bold">DropWatch</span>
        </div>

        <div class="relative max-w-md">
            <h2 class="display text-4xl font-extrabold leading-[1.05]">
                Catch the drop<br>
                <span class="bg-gradient-to-r from-sky-400 to-cyan-300 bg-clip-text text-transparent">before anyone else.</span>
            </h2>
            <p class="mt-5 text-sm leading-relaxed muted">
                DropWatch watches the global zone files, diffs each snapshot against the last, and surfaces
                the names that just left the zone with the misspellings squatters will try next.
            </p>

            <ul class="mt-8 space-y-3 text-sm">
                @foreach (['Fresh snapshot daily at 08:00 UTC', 'Weaker-version scoring with RDAP status', 'Stale zone files are never trusted'] as $point)
                    <li class="flex items-center gap-3">
                        <span class="grid h-5 w-5 place-items-center rounded-full" style="background: rgb(var(--glow) / 0.15)">
                            <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" style="color: var(--accent)">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
                            </svg>
                        </span>
                        <span class="muted">{{ $point }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        <p class="relative text-xs muted">Single-user tool &middot; {{ now()->year }}</p>
    </aside>

    {{-- Form panel --------------------------------------------------------- --}}
    <main class="relative flex items-center justify-center px-5 py-14 sm:px-10">
        <div class="pointer-events-none absolute -top-24 left-1/2 h-72 w-72 -translate-x-1/2 rounded-full opacity-30 blur-3xl lg:hidden"
             style="background: radial-gradient(circle at center, rgb(56 189 248 / 0.5), transparent 65%)"></div>

        <div class="rise relative w-full max-w-sm">
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <span class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-sky-400 to-cyan-500 font-display text-sm font-extrabold text-[color:var(--accent-ink)] shadow-lg shadow-sky-500/40">DW</span>
                <span class="display text-lg font-bold">DropWatch</span>
            </div>

            <h1 class="display text-2xl font-bold">Welcome back</h1>
            <p class="mt-1.5 text-sm muted">Sign in to the private zone monitor.</p>

            @if ($errors->any())
                <div class="mt-6 flex items-start gap-2 rounded-xl border border-rose-400/40 px-3.5 py-3 text-sm"
                     style="background: rgb(244 63 94 / 0.1); color: rgb(251 113 133)">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
                    </svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}" class="mt-7 space-y-5">
                @csrf

                <div>
                    <label for="email" class="label">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                           autocomplete="username" placeholder="you@example.com"
                           class="field mt-2">
                </div>

                <div>
                    <label for="password" class="label">Password</label>
                    <div class="relative mt-2">
                        <input id="password" name="password" type="password" required
                               autocomplete="current-password" placeholder="••••••••"
                               class="field pr-11">
                        <button type="button" data-password-toggle
                                class="absolute inset-y-0 right-0 grid w-11 place-items-center rounded-r-[0.65rem] muted transition hover:text-[color:var(--text)]">
                            <span class="sr-only">Show password</span>
                            <svg data-eye="off" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                            <svg data-eye="on" class="hidden h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4l16 16M10.6 10.6A3 3 0 0 0 12 15a3 3 0 0 0 2.4-1.2M6.2 6.6C3.9 8.1 2.5 12 2.5 12s3.5 6.5 9.5 6.5c1.6 0 3-.4 4.2-1M9.9 5.7A9.6 9.6 0 0 1 12 5.5c6 0 9.5 6.5 9.5 6.5a17 17 0 0 1-3 3.6" />
                            </svg>
                        </button>
                    </div>
                </div>

                <label class="flex cursor-pointer items-center gap-2.5 text-sm muted">
                    <input type="checkbox" name="remember" value="1"
                           class="h-4 w-4 rounded border-0" style="accent-color: var(--accent)">
                    Keep me signed in
                </label>

                <button type="submit" class="btn btn-primary w-full py-2.5">
                    Sign in
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                    </svg>
                </button>
            </form>

            <p class="mt-6 text-center text-xs muted">
                Access is limited to the operator of this deployment.
            </p>
        </div>
    </main>
</div>

<script>
    document.querySelector('[data-password-toggle]')?.addEventListener('click', function () {
        var input = document.getElementById('password');
        var showing = input.type === 'text';

        input.type = showing ? 'password' : 'text';
        this.querySelector('[data-eye="off"]').classList.toggle('hidden', !showing);
        this.querySelector('[data-eye="on"]').classList.toggle('hidden', showing);
    });
</script>
</body>
</html>
