@extends('layouts.app')

@section('title', 'Daily list')
@section('subtitle', 'Everything that left the .com zone on a given day')

@section('content')
    <form method="GET" action="{{ url('/daily') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-ink-200 bg-white p-4 dark:border-ink-800 dark:bg-ink-900">
        <div>
            <label for="date" class="block text-xs font-medium uppercase tracking-wide text-ink-500 dark:text-ink-400">Date</label>
            <input id="date" name="date" type="date" value="{{ $date }}"
                   class="mt-1 rounded-lg border border-ink-300 bg-white px-3 py-1.5 text-sm outline-none focus:border-ink-500 dark:border-ink-700 dark:bg-ink-950 dark:text-white">
        </div>

        @if (count($dates) > 0)
            <div>
                <label for="dates" class="block text-xs font-medium uppercase tracking-wide text-ink-500 dark:text-ink-400">Days with data</label>
                <select id="dates" name="date" onchange="this.form.submit()"
                        class="mt-1 rounded-lg border border-ink-300 bg-white px-3 py-1.5 text-sm outline-none focus:border-ink-500 dark:border-ink-700 dark:bg-ink-950 dark:text-white">
                    @foreach ($dates as $available)
                        <option value="{{ $available }}" @selected($available === $date)>{{ $available }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <button type="submit" class="rounded-lg bg-ink-900 px-4 py-1.5 text-sm font-semibold text-white dark:bg-white dark:text-ink-900">Show</button>

        <div class="ml-auto flex items-center gap-4 text-sm">
            <span class="text-ink-500 dark:text-ink-400">Removed <strong class="text-ink-900 dark:text-white">{{ $data['counts']['removed'] }}</strong></span>
            <span class="text-ink-500 dark:text-ink-400">Added <strong class="text-ink-900 dark:text-white">{{ $data['counts']['added'] }}</strong></span>
            <span class="text-ink-500 dark:text-ink-400">Candidates <strong class="text-ink-900 dark:text-white">{{ $data['counts']['candidates'] }}</strong></span>
        </div>
    </form>

    @include('partials.candidates', [
        'candidates' => $data['candidates'],
        'shortFilterUrl' => request()->fullUrlWithQuery([
            'date' => $date,
            'short' => $shortOnly ? null : 1,
        ]),
        'shortFilterActive' => $shortOnly,
    ])

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-xl border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-900">
            <div class="border-b border-ink-200 px-4 py-3 dark:border-ink-800">
                <h2 class="text-sm font-semibold">Removed names</h2>
                <p class="text-xs text-ink-500 dark:text-ink-400">A removed name is never available; it is in redemption or pending delete.</p>
            </div>
            <div class="max-h-96 overflow-y-auto p-4">
                @if (count($data['removed_names']) === 0)
                    <p class="py-6 text-center text-sm text-ink-500 dark:text-ink-400">Nothing was removed on this date.</p>
                @else
                    <ul class="flex flex-wrap gap-2">
                        @foreach ($data['removed_names'] as $name)
                            <li class="rounded-md bg-ink-100 px-2 py-1 font-mono text-xs text-ink-700 dark:bg-ink-800 dark:text-ink-300">{{ $name }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="rounded-xl border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-900">
            <div class="border-b border-ink-200 px-4 py-3 dark:border-ink-800">
                <h2 class="text-sm font-semibold">Added names</h2>
                <p class="text-xs text-ink-500 dark:text-ink-400">Names that appeared in the zone again.</p>
            </div>
            <div class="max-h-96 overflow-y-auto p-4">
                @if (count($data['added_names']) === 0)
                    <p class="py-6 text-center text-sm text-ink-500 dark:text-ink-400">Nothing was added on this date.</p>
                @else
                    <ul class="flex flex-wrap gap-2">
                        @foreach ($data['added_names'] as $name)
                            <li class="rounded-md bg-emerald-50 px-2 py-1 font-mono text-xs text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">{{ $name }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
@endsection
