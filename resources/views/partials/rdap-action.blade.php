@php
    $url = \App\Support\LookupLink::for($domain);
    $source = \App\Support\LookupLink::source($domain);
@endphp

<span class="inline-flex flex-wrap items-center gap-1.5"
      data-rdap data-rdap-mode="compact"
      data-domain="{{ $domain }}" data-url="{{ $url }}" data-source="{{ $source }}">
    <button type="button" data-rdap-check
            class="chip border border-[color:var(--border)] muted transition hover:text-[color:var(--text)]"
            title="Check the RDAP status for {{ $domain }}">
        Check status
    </button>

    <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
       class="text-[10px] muted underline decoration-dotted underline-offset-2 hover:text-[color:var(--text)]">
        View raw
    </a>

    <span data-rdap-badge hidden class="chip font-semibold"></span>
</span>
