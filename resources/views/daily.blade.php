@extends('layouts.app')

@section('title', 'Daily list')
@section('subtitle', 'Everything that left the .com zone on a given day')

@section('content')
    <form method="GET" action="{{ url('/daily') }}" class="card flex flex-wrap items-end gap-3 p-4">
        <div>
            <label for="date" class="label">Date</label>
            <input id="date" name="date" type="date" value="{{ $date }}" class="field mt-2">
        </div>

        @if (($data['query'] ?? '') !== '')
            <input type="hidden" name="q" value="{{ $data['query'] }}">
        @endif

        @if ($shortOnly)
            <input type="hidden" name="short" value="1">
        @endif

        @if ($endingsOnly)
            <input type="hidden" name="endings" value="1">
        @endif

        @foreach (['min_variations', 'max_variations', 'exact_variations'] as $variationFilter)
            @if ($data[$variationFilter] !== null)
                <input type="hidden" name="{{ $variationFilter }}" value="{{ $data[$variationFilter] }}">
            @endif
        @endforeach

        @if (count($dates) > 0)
            <div>
                <label for="dates" class="label">Days with data</label>
                <select id="dates" name="date" onchange="this.form.submit()" class="field mt-2 cursor-pointer">
                    @foreach ($dates as $available)
                        <option value="{{ $available }}" @selected($available === $date)>{{ $available }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <button type="submit" class="btn btn-primary">Show</button>

        <div class="ml-auto flex flex-wrap items-center gap-4 text-sm">
            <span class="muted">Removed <strong class="ml-1 display font-bold" style="color: rgb(251 113 133)">{{ number_format($data['counts']['removed']) }}</strong></span>
            <span class="muted">Added <strong class="ml-1 display font-bold" style="color: rgb(52 211 153)">{{ number_format($data['counts']['added']) }}</strong></span>
            <span class="muted">Candidates <strong class="ml-1 display font-bold" style="color: var(--accent)">{{ number_format($data['counts']['candidates']) }}</strong></span>
        </div>
    </form>

    @include('partials.daily-candidates', [
        'candidates' => $data['candidates'],
        'pagination' => $data['pagination'],
        'query' => $data['query'],
        'date' => $date,
        'shortOnly' => $shortOnly,
        'endingsOnly' => $endingsOnly,
        'minVariations' => $data['min_variations'],
        'maxVariations' => $data['max_variations'],
        'exactVariations' => $data['exact_variations'],
    ])

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="card overflow-hidden">
            <div class="border-b px-4 py-3" style="border-color: var(--border)">
                <h2 class="display text-sm font-bold">Removed names</h2>
                <p class="mt-0.5 text-xs muted">A removed name is never available; it is in redemption or pending delete.</p>
            </div>
            <div class="max-h-96 overflow-y-auto p-4">
                @if (count($data['removed_names']) === 0)
                    <p class="py-6 text-center text-sm muted">Nothing was removed on this date.</p>
                @else
                    <ul class="flex flex-wrap gap-2">
                        @foreach ($data['removed_names'] as $name)
                            <li class="rounded-lg px-2 py-1 font-mono text-xs"
                                style="background: rgb(244 63 94 / 0.12); color: rgb(251 113 133)">
                                {{ $name }}.com
                                @include('partials.rdap-action', ['domain' => $name.'.com'])
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="border-b px-4 py-3" style="border-color: var(--border)">
                <h2 class="display text-sm font-bold">Added names</h2>
                <p class="mt-0.5 text-xs muted">Names that appeared in the zone again.</p>
            </div>
            <div class="max-h-96 overflow-y-auto p-4">
                @if (count($data['added_names']) === 0)
                    <p class="py-6 text-center text-sm muted">Nothing was added on this date.</p>
                @else
                    <ul class="flex flex-wrap gap-2">
                        @foreach ($data['added_names'] as $name)
                            <li class="rounded-lg px-2 py-1 font-mono text-xs"
                                style="background: rgb(16 185 129 / 0.13); color: rgb(52 211 153)">
                                {{ $name }}.com
                                @include('partials.rdap-action', ['domain' => $name.'.com'])
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
@endsection