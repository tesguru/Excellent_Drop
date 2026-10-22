@extends('layouts.app')

@section('title', 'Overview')
@section('subtitle', 'Today at a glance')

@section('content')
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @php
            $tiles = [
                ['label' => 'Names removed', 'value' => $data['counts']['removed'], 'hint' => 'left the .com zone today'],
                ['label' => 'Names added', 'value' => $data['counts']['added'], 'hint' => 'registered in the .com zone today'],
                ['label' => 'Candidates', 'value' => $data['counts']['candidates'], 'hint' => 'with weaker versions'],
                ['label' => 'Candidates (7d)', 'value' => $data['counts']['week_candidates'], 'hint' => $data['counts']['week_removed'].' names removed'],
            ];
        @endphp

        @foreach ($tiles as $tile)
            <div class="card card-hover relative overflow-hidden p-4">
                <div class="pointer-events-none absolute -right-8 -top-8 h-24 w-24 rounded-full opacity-20 blur-2xl"
                     style="background: radial-gradient(circle, rgb(var(--glow) / 0.9), transparent 70%)"></div>
                <p class="label">{{ $tile['label'] }}</p>
                <p class="display mt-3 text-4xl font-extrabold tabular-nums">{{ number_format($tile['value']) }}</p>
                <p class="mt-1.5 text-xs muted">{{ $tile['hint'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            @include('partials.candidates', [
                'candidates' => $data['top_candidates'],
                'shortFilterUrl' => request()->fullUrlWithQuery(['short' => $data['short_only'] ? null : 1]),
                'shortFilterActive' => $data['short_only'],
            ])
        </div>

        <div class="space-y-6">
            <div class="card p-4">
                <div class="flex items-center gap-2">
                    <span class="grid h-6 w-6 place-items-center rounded-lg" style="background: rgb(var(--glow) / 0.14)">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color: var(--accent)">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7Z" />
                        </svg>
                    </span>
                    <h2 class="display text-sm font-bold">Pipeline</h2>
                </div>

                @if ($data['last_run'])
                    <dl class="mt-4 space-y-2.5 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="muted">Last step</dt>
                            <dd class="font-medium">{{ $data['last_run']['step'] }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="muted">Status</dt>
                            <dd>
                                @php $ok = $data['last_run']['status'] === 'success'; @endphp
                                <span class="chip" style="{{ $ok ? 'background: rgb(16 185 129 / 0.15); color: rgb(52 211 153)' : 'background: rgb(244 63 94 / 0.15); color: rgb(251 113 133)' }}">
                                    {{ $data['last_run']['status'] }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="muted">Finished</dt>
                            <dd class="font-medium tabular-nums">{{ $data['last_run']['finished_at'] ?? 'running' }}</dd>
                        </div>
                    </dl>
                    <p class="mt-3 border-t pt-3 text-xs muted" style="border-color: var(--border)">{{ $data['last_run']['message'] }}</p>
                @else
                    <p class="mt-3 text-sm muted">No run recorded yet.</p>
                @endif

                <a href="{{ url('/jobs') }}" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold" style="color: var(--accent)">
                    All jobs
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                    </svg>
                </a>
            </div>

            <div class="card p-4">
                <div class="flex items-center gap-2">
                    <span class="grid h-6 w-6 place-items-center rounded-lg" style="background: rgb(var(--glow) / 0.14)">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color: var(--accent)">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm0 0c2.5 2.4 4 5.6 4 9s-1.5 6.6-4 9m0-18c-2.5 2.4-4 5.6-4 9s1.5 6.6 4 9M3.5 9h17M3.5 15h17" />
                        </svg>
                    </span>
                    <h2 class="display text-sm font-bold">Zone access</h2>
                </div>

                @if (count($data['zone_access']['expiring']) === 0)
                    <p class="mt-3 text-sm muted">
                        No zone access expires within {{ $data['zone_access']['warning_days'] }} days.
                    </p>
                @else
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($data['zone_access']['expiring'] as $zone)
                            <li class="flex items-center justify-between gap-3">
                                <span class="font-mono font-medium">{{ $zone['tld'] }}</span>
                                <span class="chip" style="background: rgb(245 158 11 / 0.15); color: rgb(251 191 36)">
                                    {{ $zone['days_until_expiry'] }}d left
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <a href="{{ url('/zones') }}" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold" style="color: var(--accent)">
                    All zones
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                    </svg>
                </a>
            </div>
        </div>
    </div>
@endsection
