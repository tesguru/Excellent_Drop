{{-- Rendered only; DropWatch never fetches RDAP itself. --}}
<a href="{{ \App\Support\LookupLink::for($domain) }}"
   target="_blank" rel="noopener noreferrer"
   title="Look up {{ $domain }} in RDAP"
   class="ml-1.5 text-[10px] font-semibold uppercase tracking-wide underline decoration-dotted underline-offset-2 muted hover:text-[color:var(--accent)]">RDAP</a>
