@extends('layouts.app')

@section('title', 'Zones')
@section('subtitle', 'Access, downloads and snapshots per zone')

@section('content')
    <div class="overflow-hidden rounded-xl border border-ink-200 bg-white dark:border-ink-800 dark:bg-ink-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-ink-200 bg-ink-50 text-xs uppercase tracking-wide text-ink-500 dark:border-ink-800 dark:bg-ink-950 dark:text-ink-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">Zone</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Zone date</th>
                        <th class="px-4 py-3 font-medium">Access expires</th>
                        <th class="px-4 py-3 font-medium">Last download</th>
                        <th class="px-4 py-3 font-medium">Size</th>
                        <th class="px-4 py-3 font-medium">Snapshot</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-200 dark:divide-ink-800">
                    @forelse ($zones as $zone)
                        <tr>
                            <td class="px-4 py-3 font-mono font-medium">{{ $zone['tld'] }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-md px-2 py-0.5 text-xs font-medium {{ match ($zone['status']) {
                                    'approved', 'downloaded' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
                                    'stale' => 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
                                    default => 'bg-ink-100 text-ink-600 dark:bg-ink-800 dark:text-ink-300',
                                } }}">
                                    {{ $zone['status'] }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if ($zone['last_modified'])
                                    <span class="font-medium tabular-nums">{{ $zone['last_modified'] }}</span>
                                    <span class="block text-[11px] text-ink-400">published by ICANN</span>
                                @else
                                    <span class="text-ink-400">unknown</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($zone['expiry_at'])
                                    <span class="{{ $zone['days_until_expiry'] !== null && $zone['days_until_expiry'] <= $warningDays ? 'font-semibold text-rose-600 dark:text-rose-400' : '' }}">
                                        {{ $zone['expiry_at'] }}
                                    </span>
                                    <span class="block text-xs text-ink-500 dark:text-ink-400">{{ $zone['days_until_expiry'] }} day(s) left</span>
                                @else
                                    <span class="text-ink-400">unknown</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-ink-500 dark:text-ink-400">{{ $zone['last_download_at'] ?? 'never' }}</td>
                            <td class="px-4 py-3 text-xs tabular-nums">{{ $zone['file_size'] > 0 ? number_format($zone['file_size'] / 1048576, 1).' MB' : '-' }}</td>
                            <td class="px-4 py-3 text-xs">
                                @if ($zone['has_snapshot'])
                                    <span class="text-emerald-600 dark:text-emerald-400">today</span>
                                @else
                                    <span class="text-ink-400">missing</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-sm text-ink-500 dark:text-ink-400">
                                No zones known yet. Run the pipeline once to sync the list from CZDS.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-xs text-ink-500 dark:text-ink-400">
        Zone access is granted by ICANN for a limited period. Access that ends within
        {{ $warningDays }} days is highlighted. “Zone date” is the Last-Modified date of the
        file we actually downloaded; a stale zone is never used for a snapshot.
    </p>
@endsection
