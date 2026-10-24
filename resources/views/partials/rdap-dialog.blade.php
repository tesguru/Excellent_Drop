<dialog id="rdap-dialog" class="modal" data-modal data-rdap data-rdap-mode="dialog"
        aria-labelledby="rdap-dialog-title">
    <div class="flex items-start justify-between gap-4 border-b px-5 py-4" style="border-color: var(--border)">
        <div class="min-w-0">
            <p class="label">RDAP status</p>
            <h2 id="rdap-dialog-title" data-rdap-dialog-domain class="display mt-1 truncate text-xl font-extrabold">
                Domain
            </h2>
            <p class="mt-1 text-xs muted">
                Checked in your browser. DropWatch's server never contacts RDAP.
            </p>
        </div>

        <button type="button" data-modal-close class="btn btn-ghost shrink-0 p-2" aria-label="Close">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6 6 18" />
            </svg>
        </button>
    </div>

    <div class="max-h-[calc(100dvh-9rem)] space-y-3 overflow-y-auto overscroll-contain px-5 py-4">
        <p data-rdap-dialog-status class="text-sm muted">Ready to check.</p>

        @include('partials.rdap-result')

        <div class="flex flex-wrap gap-2">
            <button type="button" data-rdap-check class="btn btn-primary px-3 py-1.5 text-xs">
                Check status
            </button>
            <a data-rdap-dialog-raw href="#" target="_blank" rel="noopener noreferrer"
               class="btn btn-ghost px-3 py-1.5 text-xs">
                View raw
            </a>
        </div>
    </div>
</dialog>
