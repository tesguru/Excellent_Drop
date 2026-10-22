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
            <div class="rounded-xl border border-ink-200 bg-white p-4 dark:border-ink-800 dark:bg-ink-900">
                <p class="text-xs font-medium uppercase tracking-wide text-ink-500 dark:text-ink-400">{{ $tile['label'] }}</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums">{{ number_format($tile['value']) }}</p>
                <p class="mt-1 text-xs text-ink-500 dark:text-ink-400">{{ $tile['hint'] }}</p>
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
            <div class="rounded-xl border border-ink-200 bg-white p-4 dark:border-ink-800 dark:bg-ink-900">
                <h2 class="text-sm font-semibold">Pipeline</h2>

                @if ($data['last_run'])
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-ink-500 dark:text-ink-400">Last step</dt>
                            <dd class="font-medium">{{ $data['last_run']['step'] }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-ink-500 dark:text-ink-400">Status</dt>
                            <dd class="font-medium">{{ $data['last_run']['status'] }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-ink-500 dark:text-ink-400">Finished</dt>
                            <dd class="font-medium">{{ $data['last_run']['finished_at'] ?? 'running' }}</dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-xs text-ink-500 dark:text-ink-400">{{ $data['last_run']['message'] }}</p>
                @else
                    <p class="mt-3 text-sm text-ink-500 dark:text-ink-400">No run recorded yet.</p>
                @endif

                <a href="{{ url('/jobs') }}" class="mt-4 inline-block text-sm font-medium text-ink-900 underline dark:text-white">All jobs</a>
            </div>

            <div class="rounded-xl border border-ink-200 bg-white p-4 dark:border-ink-800 dark:bg-ink-900">
                <h2 class="text-sm font-semibold">Zone access</h2>

                @if (count($data['zone_access']['expiring']) === 0)
                    <p class="mt-3 text-sm text-ink-500 dark:text-ink-400">
                        No zone access expires within {{ $data['zone_access']['warning_days'] }} days.
                    </p>
                @else
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($data['zone_access']['expiring'] as $zone)
                            <li class="flex items-center justify-between gap-3">
                                <span class="font-mono">{{ $zone['tld'] }}</span>
                                <span class="text-ink-500 dark:text-ink-400">
                                    {{ $zone['expiry_at'] }} ({{ $zone['days_until_expiry'] }}d)
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <a href="{{ url('/zones') }}" class="mt-4 inline-block text-sm font-medium text-ink-900 underline dark:text-white">All zones</a>
            </div>
        </div>
    </div>
@endsection
