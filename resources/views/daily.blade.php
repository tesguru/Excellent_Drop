@extends('layouts.app')

@section('title', 'Daily list')
@section('subtitle', 'Drop candidates and pending-delete candidates for .com')

@section('content')
    <div class="grid items-start gap-6 lg:grid-cols-[15rem_minmax(0,1fr)]">
        <aside class="card overflow-hidden">
            <div class="border-b px-4 py-3" style="border-color: var(--border)">
                <h2 class="display text-sm font-bold">Daily lists</h2>
            </div>

            <nav class="flex flex-col gap-1 p-2" aria-label="Daily lists">
                @foreach ([
                    'candidates' => ['label' => 'Drop candidates', 'count' => $data['counts']['candidates']],
                    'pending' => ['label' => 'Pending delete candidates', 'count' => $data['counts']['pending']],
                ] as $listKey => $entry)
                    @php
                        $href = $listKey === 'candidates'
                            ? url('/daily?date='.$date.'&list='.$listKey)
                            : url('/daily?list='.$listKey);
                    @endphp

                    <a href="{{ $href }}"
                       class="flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm transition {{ $list === $listKey ? 'font-semibold' : 'muted hover:text-[color:var(--text)]' }}"
                       @if ($list === $listKey) style="background: rgb(var(--glow) / 0.14); color: var(--accent)" @endif>
                        <span>{{ $entry['label'] }}</span>
                        <span class="chip tabular-nums" @if ($list === $listKey) style="background: rgb(var(--glow) / 0.22)" @else style="background: var(--surface-2)" @endif>
                            {{ number_format($entry['count']) }}
                        </span>
                    </a>
                @endforeach
            </nav>
        </aside>

        <div class="min-w-0">
            @if ($list === 'candidates')
                <form method="GET" action="{{ url('/daily') }}" class="card mb-6 flex flex-wrap items-end gap-3 p-4">
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
        </div>
    </div>
@endsection
