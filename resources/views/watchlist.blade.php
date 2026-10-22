@extends('layouts.app')

@section('title', 'Watchlist')
@section('subtitle', 'Domains you keep an eye on')

@section('content')
    <form id="watchlist-form" class="flex flex-wrap items-end gap-3 rounded-xl border border-ink-200 bg-white p-4 dark:border-ink-800 dark:bg-ink-900">
        <div class="min-w-56 flex-1">
            <label for="domain" class="block text-xs font-medium uppercase tracking-wide text-ink-500 dark:text-ink-400">Domain</label>
            <input id="domain" name="domain" type="text" placeholder="example.com" required
                   class="mt-1 w-full rounded-lg border border-ink-300 bg-white px-3 py-2 text-sm outline-none focus:border-ink-500 dark:border-ink-700 dark:bg-ink-950 dark:text-white">
        </div>
        <div>
            <label for="status" class="block text-xs font-medium uppercase tracking-wide text-ink-500 dark:text-ink-400">Status</label>
            <select id="status" name="status"
                    class="mt-1 rounded-lg border border-ink-300 bg-white px-3 py-2 text-sm outline-none focus:border-ink-500 dark:border-ink-700 dark:bg-ink-950 dark:text-white">
                @foreach ($statuses as $status)
                    <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="rounded-lg bg-ink-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-ink-900">Add</button>
    </form>

    <p id="watchlist-message" class="hidden text-sm"></p>

    <div id="watchlist" class="space-y-4">
        @forelse ($entries as $entry)
            <div class="rounded-xl border border-ink-200 bg-white p-4 dark:border-ink-800 dark:bg-ink-900" data-watch-entry data-domain="{{ $entry['domain'] }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-mono text-sm font-semibold">{{ $entry['domain'] }}</p>
                        <p class="mt-1 text-xs text-ink-500 dark:text-ink-400">
                            Added {{ $entry['created_at'] }}
                            @if ($entry['candidate'])
                                &middot; score {{ number_format((float) $entry['candidate']['score'], 1) }}
                                @if (! empty($entry['candidate']['predicted_drop_date']))
                                    &middot; predicted drop {{ $entry['candidate']['predicted_drop_date'] }}
                                @endif
                            @endif
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <select class="watch-status rounded-lg border border-ink-300 bg-white px-2 py-1 text-xs outline-none dark:border-ink-700 dark:bg-ink-950 dark:text-ink-200">
                            @foreach ($statuses as $status)
                                <option value="{{ $status }}" @selected($status === $entry['status'])>{{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                        <button type="button" class="watch-remove rounded-lg border border-ink-300 px-2.5 py-1 text-xs font-medium text-ink-600 hover:bg-ink-100 dark:border-ink-700 dark:text-ink-300 dark:hover:bg-ink-800">Remove</button>
                    </div>
                </div>

                @if (! empty($entry['candidate']['weaker_found']))
                    <ul class="mt-3 flex flex-wrap gap-2">
                        @foreach ($entry['candidate']['weaker_found'] as $index => $domain)
                            <li class="rounded-md bg-ink-100 px-2 py-1 font-mono text-xs dark:bg-ink-800 dark:text-ink-300">{{ $domain }}</li>
                        @endforeach
                    </ul>
                @endif

                @if (! empty($entry['notes_list']))
                    <ul class="mt-3 space-y-1 border-l-2 border-ink-200 pl-3 text-sm dark:border-ink-800">
                        @foreach ($entry['notes_list'] as $note)
                            <li>
                                <span class="text-ink-500 dark:text-ink-400">{{ $note['created_at'] }}</span>
                                <span class="ml-2">{{ $note['body'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <form class="mt-3 flex gap-2" data-note-form>
                    @csrf
                    <input type="text" name="body" placeholder="Add a note" required
                           class="flex-1 rounded-lg border border-ink-300 bg-white px-3 py-1.5 text-sm outline-none focus:border-ink-500 dark:border-ink-700 dark:bg-ink-950 dark:text-white">
                    <button type="submit" class="rounded-lg border border-ink-300 px-3 py-1.5 text-sm font-medium text-ink-600 hover:bg-ink-100 dark:border-ink-700 dark:text-ink-300 dark:hover:bg-ink-800">Save note</button>
                </form>
            </div>
        @empty
            <p class="rounded-xl border border-dashed border-ink-300 p-8 text-center text-sm text-ink-500 dark:border-ink-700 dark:text-ink-400">
                The watchlist is empty.
            </p>
        @endforelse
    </div>
@endsection
