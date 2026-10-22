@extends('layouts.app')

@section('title', 'Zones')
@section('subtitle', 'Access, downloads and snapshots per zone')

@section('content')
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b text-xs uppercase tracking-wider muted" style="border-color: var(--border); background: var(--surface-2)">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Zone</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 font-semibold">Zone date</th>
                        <th class="px-4 py-3 font-semibold">Access expires</th>
                        <th class="px-4 py-3 font-semibold">Last download</th>
                        <th class="px-4 py-3 font-semibold">Size</th>
                        <th class="px-4 py-3 font-semibold">Snapshot</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($zones as $zone)
                        <tr class="border-b transition last:border-0" style="border-color: var(--border)"
                            onmouseover="this.style.background='rgb(var(--glow) / 0.03)'"
                            onmouseout="this.style.background='transparent'">
                            <td class="px-4 py-3 font-mono font-semibold">.{{ $zone['tld'] }}</td>
                            <td class="px-4 py-3">
                                @php
                                    $statusStyle = match ($zone['status']) {
                                        'approved', 'downloaded', 'loaded' => 'background: rgb(16 185 129 / 0.15); color: rgb(52 211 153)',
                                        'stale', 'failed' => 'background: rgb(244 63 94 / 0.15); color: rgb(251 113 133)',
                                        default => 'background: var(--surface-2); color: var(--muted)',
                                    };
                                @endphp
                                <span class="chip" style="{{ $statusStyle }}">{{ $zone['status'] }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if ($zone['last_modified'])
                                    <span class="font-medium tabular-nums">{{ $zone['last_modified'] }}</span>
                                    <span class="block text-[11px] muted">published by ICANN</span>
                                @else
                                    <span class="muted opacity-60">unknown</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($zone['expiry_at'])
                                    @php $soon = $zone['days_until_expiry'] !== null && $zone['days_until_expiry'] <= $warningDays; @endphp
                                    <span class="{{ $soon ? 'font-semibold' : '' }}" style="{{ $soon ? 'color: rgb(251 113 133)' : '' }}">
                                        {{ $zone['expiry_at'] }}
                                    </span>
                                    <span class="block text-xs {{ $soon ? '' : 'muted' }}" style="{{ $soon ? 'color: rgb(251 113 133)' : '' }}">
                                        {{ $zone['days_until_expiry'] }} day(s) left
                                    </span>
                                @else
                                    <span class="muted opacity-60">unknown</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs muted">{{ $zone['last_download_at'] ?? 'never' }}</td>
                            <td class="px-4 py-3 text-xs tabular-nums">{{ $zone['file_size'] > 0 ? number_format($zone['file_size'] / 1048576, 1).' MB' : '-' }}</td>
                            <td class="px-4 py-3 text-xs">
                                @if ($zone['has_snapshot'])
                                    <span class="chip" style="background: rgb(16 185 129 / 0.15); color: rgb(52 211 153)">today</span>
                                @else
                                    <span class="muted opacity-60">missing</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-sm muted">
                                No zones known yet. Run the pipeline once to sync the list from CZDS.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="flex items-start gap-2 text-xs muted">
        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z" />
        </svg>
        <span>
            Zone access is granted by ICANN for a limited period. Access that ends within
            {{ $warningDays }} days is highlighted. “Zone date” is the Last-Modified date of the
            file we actually downloaded; a stale zone is never used for a snapshot.
        </span>
    </p>
@endsection
