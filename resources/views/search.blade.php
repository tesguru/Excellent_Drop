@extends('layouts.app')

@section('title', 'Search')
@section('subtitle', 'Search the latest zone snapshot across every TLD')

@section('content')
    <form id="zone-search-form" method="GET" action="{{ url('/search') }}" class="card p-4">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="md:col-span-2">
                <label for="q" class="label">Name</label>
                <input id="q" name="q" type="search" value="{{ $data['query'] }}" placeholder="e.g. acmeplumbing"
                       autocomplete="off" class="field mt-2">
                <p class="mt-1 text-[11px] muted">Contains needs at least {{ \App\Services\Zone\ZoneSearch::CONTAINS_MIN_LENGTH }} characters.</p>
            </div>

            <div>
                <label for="mode" class="label">Mode</label>
                <select id="mode" name="mode" class="field mt-2 cursor-pointer">
                    @foreach (['contains' => 'Contains', 'starts' => 'Starts with', 'ends' => 'Ends with', 'exact' => 'Exact'] as $value => $label)
                        <option value="{{ $value }}" @selected($data['mode'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="tld" class="label">TLD</label>
                <select id="tld" name="tld[]" multiple size="4" class="field mt-2 h-auto cursor-pointer">
                    @foreach ($tldOptions as $tld)
                        <option value="{{ $tld }}" @selected(in_array($tld, $data['tlds'], true))>{{ $tld }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-[11px] muted">Hold Cmd/Ctrl to pick several.</p>
            </div>

            <div>
                <label for="min_length" class="label">Min length</label>
                <input id="min_length" name="min_length" type="number" min="1" max="63"
                       value="{{ $data['min_length'] }}" class="field mt-2">
            </div>

            <div>
                <label for="max_length" class="label">Max length</label>
                <input id="max_length" name="max_length" type="number" min="1" max="63"
                       value="{{ $data['max_length'] }}" class="field mt-2">
            </div>

            <div class="flex items-end gap-4 pb-2">
                <label class="flex items-center gap-2 text-sm muted">
                    <input type="checkbox" name="hide_digits" value="1" @checked($data['hide_digits'])> Hide digits
                </label>
                <label class="flex items-center gap-2 text-sm muted">
                    <input type="checkbox" name="hide_hyphens" value="1" @checked($data['hide_hyphens'])> Hide hyphens
                </label>
            </div>

            <div class="flex items-end">
                <button type="submit" class="btn btn-primary">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Zm5.5-1.5L21 21" />
                    </svg>
                    Search
                </button>
            </div>
        </div>
    </form>

    <div data-search-results>
        @include('partials.zone-results', ['data' => $data])
    </div>
@endsection
