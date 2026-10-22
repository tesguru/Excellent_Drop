<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in &middot; DropWatch</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
</head>
<body class="grid h-full place-items-center bg-ink-50 px-4 dark:bg-ink-950">
<div class="w-full max-w-sm">
    <div class="mb-6 flex items-center justify-center gap-2">
        <span class="grid h-10 w-10 place-items-center rounded-lg bg-ink-900 text-sm font-bold text-white dark:bg-white dark:text-ink-900">DW</span>
        <span class="text-lg font-semibold text-ink-900 dark:text-white">DropWatch</span>
    </div>

    <div class="rounded-xl border border-ink-200 bg-white p-6 shadow-sm dark:border-ink-800 dark:bg-ink-900">
        <h1 class="text-base font-semibold text-ink-900 dark:text-white">Sign in</h1>
        <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">This dashboard is private.</p>

        @if ($errors->any())
            <div class="mt-4 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-300">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login.attempt') }}" class="mt-5 space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-ink-700 dark:text-ink-300">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                       autocomplete="username"
                       class="mt-1 block w-full rounded-lg border border-ink-300 bg-white px-3 py-2 text-sm outline-none focus:border-ink-500 focus:ring-2 focus:ring-ink-500/20 dark:border-ink-700 dark:bg-ink-950 dark:text-white">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-ink-700 dark:text-ink-300">Password</label>
                <input id="password" name="password" type="password" required
                       autocomplete="current-password"
                       class="mt-1 block w-full rounded-lg border border-ink-300 bg-white px-3 py-2 text-sm outline-none focus:border-ink-500 focus:ring-2 focus:ring-ink-500/20 dark:border-ink-700 dark:bg-ink-950 dark:text-white">
            </div>

            <label class="flex items-center gap-2 text-sm text-ink-600 dark:text-ink-400">
                <input type="checkbox" name="remember" value="1"
                       class="h-4 w-4 rounded border-ink-300 text-ink-900 focus:ring-ink-500 dark:border-ink-700 dark:bg-ink-950">
                Remember me
            </label>

            <button type="submit" class="w-full rounded-lg bg-ink-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-ink-800 dark:bg-white dark:text-ink-900 dark:hover:bg-ink-200">
                Sign in
            </button>
        </form>
    </div>
</div>
</body>
</html>
