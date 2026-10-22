@extends('layouts.app')

@section('title', 'Jobs')
@section('subtitle', 'Every step of every run')

@section('content')
    <div id="jobs" class="space-y-3">
        @forelse ($runs as $run)
            <a href="{{ url('/jobs/' . $run['run_id']) }}"
               class="card card-hover block p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-mono text-xs muted">{{ $run['run_id'] }}</p>
                        <p class="mt-1.5 display text-sm font-bold">
                            {{ $run['step_count'] }} step(s), {{ number_format($run['total_rows']) }} rows
                        </p>
                    </div>

                    <div class="flex items-center gap-3 text-xs muted">
                        <span class="tabular-nums">{{ $run['started_at'] ?? 'queued' }}</span>
                        @php
                            $runStyle = match ($run['status']) {
                                'failed' => 'background: rgb(244 63 94 / 0.15); color: rgb(251 113 133)',
                                'success' => 'background: rgb(16 185 129 / 0.15); color: rgb(52 211 153)',
                                default => 'background: rgb(245 158 11 / 0.15); color: rgb(251 191 36)',
                            };
                        @endphp
                        <span class="chip" style="{{ $runStyle }}">{{ $run['status'] }}</span>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach ($run['steps'] as $step)
                        <span class="rounded-md px-2 py-0.5 font-mono text-[11px]" style="background: var(--surface-2)">
                            {{ $step['step'] }}{{ $step['tld'] ? ':'.$step['tld'] : '' }}
                        </span>
                    @endforeach
                </div>

                @if (! empty($run['error']))
                    <p class="mt-2 text-xs" style="color: rgb(251 113 133)">{{ $run['error'] }}</p>
                @endif
            </a>
        @empty
            <div class="card grid place-items-center border-dashed p-12 text-center" style="border-style: dashed">
                <p class="text-sm muted">No runs recorded yet.</p>
            </div>
        @endforelse
    </div>
@endsection
