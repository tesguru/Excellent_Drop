@extends('layouts.app')

@section('title', 'Run')
@section('subtitle', $runId)

@section('content')
    <a href="{{ url('/jobs') }}" class="inline-block text-sm font-medium text-ink-900 underline dark:text-white">&larr; All jobs</a>

    <div class="mt-4 overflow-hidden rounded-xl border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-ink-200 bg-ink-50 text-xs uppercase tracking-wide text-ink-500 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">Step</th>
                        <th class="px-4 py-3 font-medium">TLD</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Started</th>
                        <th class="px-4 py-3 font-medium">Duration</th>
                        <th class="px-4 py-3 font-medium">Rows</th>
                        <th class="px-4 py-3 font-medium">Message</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-200 dark:divide-ink-800">
                    @forelse ($steps as $step)
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $step['step'] }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $step['tld'] ?? '-' }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-md px-2 py-0.5 text-xs font-medium {{ $step['status'] === 'failed' ? 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300' : ($step['status'] === 'success' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : ($step['status'] === 'skipped' ? 'bg-ink-100 text-ink-600 dark:bg-ink-800 dark:text-ink-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300')) }}">
                                    {{ $step['status'] }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-ink-500 dark:text-ink-400">{{ $step['started_at'] ?? '-' }}</td>
                            <td class="px-4 py-3 text-xs text-ink-500 dark:text-ink-400">{{ $step['duration'] !== null ? $step['duration'].'s' : '-' }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ $step['rows'] !== null ? number_format($step['rows']) : '-' }}</td>
                            <td class="px-4 py-3 text-xs text-ink-600 dark:text-ink-300">
                                {{ $step['message'] ?? '' }}
                                @if (! empty($step['error']))
                                    <span class="mt-1 block text-rose-600 dark:text-rose-400">{{ $step['error'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-sm text-ink-500 dark:text-ink-400">No steps found for this run.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
