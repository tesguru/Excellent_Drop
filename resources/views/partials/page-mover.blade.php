@php
    $page = max(1, (int) ($page ?? 1));
    $pages = max(1, (int) ($pages ?? 1));
@endphp

@if ($pages > 1)
    <form method="GET" action="{{ request()->url() }}" data-page-mover class="flex flex-wrap items-center gap-1.5">
        @foreach (request()->query() as $key => $value)
            @continue($key === 'page')

            @if (is_array($value))
                @foreach ($value as $item)
                    @if (is_scalar($item))
                        <input type="hidden" name="{{ $key }}[]" value="{{ $item }}">
                    @endif
                @endforeach
            @elseif (is_scalar($value))
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach

        <label class="flex items-center gap-1.5 text-xs muted">
            Go to
            <input type="number" name="page" min="1" max="{{ $pages }}" step="1" value="{{ $page }}"
                   inputmode="numeric" aria-label="Go to page"
                   class="field w-16 px-2 py-1 text-xs tabular-nums">
            of {{ number_format($pages) }}
        </label>

        <button type="submit" class="btn btn-ghost px-2 py-1 text-xs">Go</button>
    </form>
@endif
