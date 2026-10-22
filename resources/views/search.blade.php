@extends('layouts.app')

@section('title', 'Search')
@section('subtitle', 'Find a dropped name and its weaker versions')

@section('content')
    <form method="GET" action="{{ url('/search') }}" class="flex flex-wrap gap-3 rounded-xl border border-ink-200 bg-white p-4 dark:border-ink-800 dark:bg-ink-900">
        <div class="min-w-64 flex-1">
            <label for="q" class="block text-xs font-medium uppercase tracking-wide text-ink-500 dark:text-ink-400">Name</label>
            <input id="q" name="q" type="search" value="{{ $query }}" placeholder="e.g. acmeplumbing"
                   class="mt-1 w-full rounded-lg border border-ink-300 bg-white px-3 py-2 text-sm outline-none focus:border-ink-500 dark:border-ink-700 dark:bg-ink-950 dark:text-white">
        </div>
        <button type="submit" class="mt-auto rounded-lg bg-ink-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-ink-900">Search</button>
    </form>

    @if ($query === '')
        <p class="rounded-xl border border-dashed border-ink-300 p-8 text-center text-sm text-ink-500 dark:border-ink-700 dark:text-ink-400">
            Type a name to search the candidate history.
        </p>
    @else
        @include('partials.candidates', ['candidates' => $results])
    @endif
@endsection
