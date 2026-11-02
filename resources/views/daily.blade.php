@extends('layouts.app')

@section('title', 'Daily list')
@section('subtitle', 'Domains that dropped on a given day, across every supported TLD')

@section('content')
    <form method="GET" action="{{ url('/daily') }}" class="card flex flex-wrap items-end gap-3 p-4">
        <div>
            <label for="date" class="label">Date</label>
            <input id="date" name="date" type="date" value="{{ $date }}" class="field mt-2">
        </div>

        <input type="hidden" name="list" value="{{ $list }}">

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

    <div class="mt-4 flex flex-wrap items-center gap-2">
        @foreach ([
            'candidates' => 'Candidates',
            'dropped' => 'Dropped',
            'pending' => 'Pending delete',
        ] as $listKey => $listLabel)
            <a href="{{ url('/daily?date='.$date.'&list='.$listKey) }}"
               class="chip border transition {{ $list === $listKey ? 'border-transparent text-[color:var(--accent-ink)]' : 'muted border-[color:var(--border)] hover:text-[color:var(--text)]' }}"
               @if ($list === $listKey) style="background: var(--accent)" @else style="background: var(--surface-2)" @endif>
                {{ $listLabel }}
            </a>
        @endforeach
    </div>

    @if ($list === 'candidates')
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
        @include('partials.daily-names', [
            'names' => $data['names'],
            'pagination' => $data['pagination'],
            'query' => $data['query'],
            'date' => $date,
            'list' => $list,
        ])
    @endif
@endsection