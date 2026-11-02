@extends('layouts.app')

@if ($list === 'pending')
    @section('title', 'Pending Daily List')
    @section('subtitle', 'Scored weaker versions for .com names heading for deletion')
@else
    @section('title', 'Drop Daily List')
    @section('subtitle', 'Scored weaker versions for the .com names that dropped')
@endif

@section('content')
    @if ($list === 'candidates')
        <form method="GET" action="{{ url('/daily') }}" class="card mb-6 flex flex-wrap items-end gap-3 p-4">
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
                @if (($data[$variationFilter] ?? null) !== null)
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
                <span class="muted">Dropped <strong class="ml-1 display font-bold" style="color: rgb(251 113 133)">{{ number_format($data['counts']['dropped']) }}</strong></span>
                <span class="muted">Pending <strong class="ml-1 display font-bold" style="color: rgb(52 211 153)">{{ number_format($data['counts']['pending']) }}</strong></span>
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
    @else
        @include('partials.daily-pending-candidates', [
            'candidates' => $data['candidates'],
            'pagination' => $data['pagination'],
            'query' => $data['query'],
        ])
    @endif
@endsection
