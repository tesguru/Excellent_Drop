@extends('layouts.app')

@section('title', 'Run')
@section('subtitle', $runId)

@section('content')
    <a href="{{ url('/jobs') }}" class="btn btn-ghost w-fit px-3 py-1.5 text-xs">
        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 12H5m5 5-5-5 5-5" />
        </svg>
        All jobs
    </a>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b text-xs uppercase tracking-wider muted" style="border-color: var(--border); background: var(--surface-2)">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Step</th>
                        <th class="px-4 py-3 font-semibold">TLD</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 font-semibold">Started</th>
                        <th class="px-4 py-3 font-semibold">Duration</th>
                        <th class="px-4 py-3 font-semibold">Rows</th>
                        <th class="px-4 py-3 font-semibold">Message</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($steps as $step)
                        <tr class="border-b last:border-0 align-top" style="border-color: var(--border)">
                            <td class="px-4 py-3 font-medium">{{ $step['step'] }}</td>
                            <td class="px-4 py-3 font-mono text-xs muted">{{ $step['tld'] ?? '-' }}</td>
                            <td class="px-4 py-3">
                                @php
                                    $stepStyle = match ($step['status']) {
                                        'failed' => 'background: rgb(244 63 94 / 0.15); color: rgb(251 113 133)',
                                        'success' => 'background: rgb(16 185 129 / 0.15); color: rgb(52 211 153)',
                                        'skipped' => 'background: var(--surface-2); color: var(--muted)',
                                        default => 'background: rgb(245 158 11 / 0.15); color: rgb(251 191 36)',
                                    };
                                @endphp
                                <span class="chip" style="{{ $stepStyle }}">{{ $step['status'] }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs muted tabular-nums">{{ $step['started_at'] ?? '-' }}</td>
                            <td class="px-4 py-3 text-xs muted tabular-nums">{{ $step['duration'] !== null ? $step['duration'].'s' : '-' }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ $step['rows'] !== null ? number_format($step['rows']) : '-' }}</td>
                            <td class="px-4 py-3 text-xs muted">
                                {{ $step['message'] ?? '' }}
                                @if (! empty($step['error']))
                                    <span class="mt-1 block" style="color: rgb(251 113 133)">{{ $step['error'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-sm muted">No steps found for this run.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
