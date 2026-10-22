@extends('layouts.app')

@section('title', 'Search')
@section('subtitle', 'Find a dropped name and its weaker versions')

@section('content')
    <form method="GET" action="{{ url('/search') }}" class="card flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-64 flex-1">
            <label for="q" class="label">Name</label>
            <input id="q" name="q" type="search" value="{{ $query }}" placeholder="e.g. acmeplumbing"
                   class="field mt-2">
        </div>
        <button type="submit" class="btn btn-primary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Zm5.5-1.5L21 21" />
            </svg>
            Search
        </button>
    </form>

    @if ($query === '')
        <div class="card grid place-items-center p-12 text-center">
            <div class="mx-auto max-w-sm">
                <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl" style="background: rgb(var(--glow) / 0.12)">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" style="color: var(--accent)">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Zm5.5-1.5L21 21" />
                    </svg>
                </span>
                <p class="mt-4 text-sm muted">Type a name to search the candidate history.</p>
            </div>
        </div>
    @else
        @include('partials.candidates', ['candidates' => $results])
    @endif
@endsection
