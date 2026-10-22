@extends('layouts.app')

@section('title', 'Jobs')
@section('subtitle', 'Every step of every run')

@section('content')
    <div id="jobs" class="space-y-3">
        @forelse ($runs as $run)
            <a href="{{ url('/jobs/' . $run['run_id']) }}"
               class="block rounded-xl border border-ink-200 bg-white p-4 transition hover:border-ink-300 dark:border-ink-800 dark:bg-ink-900 dark:hover:border-ink-700">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-mono text-xs text-ink-500 dark:text-ink-400">{{ $run['run_id'] }}</p>
                        <p class="mt-1 text-sm font-medium">
                            {{ $run['step_count'] }} step(s), {{ number_format($run['total_rows']) }} rows
                        </p>
                    </div>

                    <div class="flex items-center gap-3 text-xs text-ink-500 dark:text-ink-400">
                        <span>{{ $run['started_at'] ?? 'queued' }}</span>
                        <span class="rounded-md px-2 py-0.5 font-medium {{ $run['status'] === 'failed' ? 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300' : ($run['status'] === 'success' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300') }}">
                            {{ $run['status'] }}
                        </span>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach ($run['steps'] as $step)
                        <span class="rounded-md bg-ink-100 px-2 py-0.5 font-mono text-[11px] text-ink-600 dark:bg-ink-800 dark:text-ink-300">
                            {{ $step['step'] }}{{ $step['tld'] ? ':'.$step['tld'] : '' }}
                        </span>
                    @endforeach
                </div>

                @if (! empty($run['error']))
                    <p class="mt-2 text-xs text-rose-600 dark:text-rose-400">{{ $run['error'] }}</p>
                @endif
            </a>
        @empty
            <p class="rounded-xl border border-dashed border-ink-300 p-8 text-center text-sm text-ink-500 dark:border-ink-700 dark:text-ink-400">
                No runs recorded yet.
            </p>
        @endforelse
    </div>
@endsection
