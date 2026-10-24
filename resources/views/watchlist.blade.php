@extends('layouts.app')

@section('title', 'Watchlist')
@section('subtitle', 'Domains you keep an eye on')

@section('content')
    <form id="watchlist-form" class="card flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-56 flex-1">
            <label for="domain" class="label">Domain</label>
            <input id="domain" name="domain" type="text" placeholder="example.com" required
                   class="field mt-2">
        </div>
        <div>
            <label for="status" class="label">Status</label>
            <select id="status" name="status" class="field mt-2 cursor-pointer">
                @foreach ($statuses as $status)
                    <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Add</button>
    </form>

    <p id="watchlist-message" class="hidden text-sm"></p>

    <div id="watchlist" class="space-y-4">
        @forelse ($entries as $entry)
            <div class="card p-4" data-watch-entry data-domain="{{ $entry['domain'] }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-mono text-sm font-semibold">
                            {{ $entry['domain'] }}
                            @include('partials.lookup-link', ['domain' => $entry['domain']])
                        </p>
                        <p class="mt-1.5 text-xs muted">
                            Added {{ $entry['created_at'] }}
                            @if ($entry['candidate'])
                                &middot; score {{ number_format((float) $entry['candidate']['score'], 1) }}
                                @if (! empty($entry['candidate']['estimated_drop_date']))
                                    &middot; estimated drop {{ $entry['candidate']['estimated_drop_date'] }}
                                @endif
                            @endif
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <select class="watch-status field w-auto px-2 py-1 text-xs cursor-pointer">
                            @foreach ($statuses as $status)
                                <option value="{{ $status }}" @selected($status === $entry['status'])>{{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                        <button type="button" class="watch-remove btn btn-ghost px-2.5 py-1 text-xs">Remove</button>
                    </div>
                </div>

                @if (! empty($entry['candidate']['weaker_found']))
                    <ul class="mt-3 flex flex-wrap gap-2">
                        @foreach ($entry['candidate']['weaker_found'] as $domain)
                            <li class="rounded-lg px-2 py-1 font-mono text-xs" style="background: var(--surface-2)">
                                {{ $domain }}
                                @include('partials.lookup-link', ['domain' => $domain])
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if (! empty($entry['notes_list']))
                    <ul class="mt-3 space-y-1.5 border-l-2 pl-3 text-sm" style="border-color: rgb(var(--glow) / 0.4)">
                        @foreach ($entry['notes_list'] as $note)
                            <li>
                                <span class="text-xs muted">{{ $note['created_at'] }}</span>
                                <span class="ml-2">{{ $note['body'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <form class="mt-3 flex gap-2" data-note-form>
                    @csrf
                    <input type="text" name="body" placeholder="Add a note" required
                           class="field flex-1">
                    <button type="submit" class="btn btn-ghost">Save note</button>
                </form>
            </div>
        @empty
            <div class="card grid place-items-center border-dashed p-12 text-center" style="border-style: dashed">
                <div class="mx-auto max-w-sm">
                    <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl" style="background: rgb(var(--glow) / 0.12)">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" style="color: var(--accent)">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5c-4 0-7 3-8.5 7.5C5 16.5 8 19.5 12 19.5s7-3 8.5-7.5C19 7.5 16 4.5 12 4.5Zm0 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
                        </svg>
                    </span>
                    <p class="mt-4 text-sm muted">The watchlist is empty. Add a domain above to start tracking it.</p>
                </div>
            </div>
        @endforelse
    </div>
@endsection
