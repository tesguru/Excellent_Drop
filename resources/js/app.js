(function () {
    'use strict';

    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    function headers(extra) {
        return Object.assign({
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': token || '',
            'X-Requested-With': 'XMLHttpRequest',
        }, extra || {});
    }

    async function request(method, url, body) {
        const options = { method: method, headers: headers(), credentials: 'same-origin' };

        if (body !== undefined) {
            options.body = JSON.stringify(body);
        }

        const response = await fetch(url, options);

        if (!response.ok) {
            let message = 'Request failed (' + response.status + ')';

            try {
                const payload = await response.json();
                message = payload.message || payload.error || message;
            } catch (error) {
                // Keep the default message.
            }

            throw new Error(message);
        }

        return response.status === 204 ? null : response.json();
    }

    // Sidebar ----------------------------------------------------------------
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
    const menuToggle = document.getElementById('menu-toggle');

    function closeSidebar() {
        sidebar?.classList.add('-translate-x-full');
        overlay?.classList.add('hidden');
    }

    menuToggle?.addEventListener('click', function () {
        sidebar?.classList.toggle('-translate-x-full');
        overlay?.classList.toggle('hidden');
    });

    overlay?.addEventListener('click', closeSidebar);

    // Theme ------------------------------------------------------------------
    const themeToggle = document.getElementById('theme-toggle');
    const themeIcons = document.querySelectorAll('[data-theme-icon]');

    function syncThemeIcons() {
        const isDark = document.documentElement.classList.contains('dark');

        themeIcons.forEach(function (icon) {
            const match = icon.getAttribute('data-theme-icon') === (isDark ? 'dark' : 'light');

            icon.classList.toggle('hidden', !match);
        });
    }

    syncThemeIcons();

    themeToggle?.addEventListener('click', function () {
        const isDark = document.documentElement.classList.toggle('dark');

        localStorage.setItem('dropwatch-theme', isDark ? 'dark' : 'light');
        syncThemeIcons();
    });

    // Worker heartbeat -------------------------------------------------------
    const workerDot = document.querySelector('[data-worker-dot]');
    const workerLabel = document.querySelector('[data-worker-label]');

    async function refreshWorker() {
        if (!workerDot || !workerLabel) {
            return;
        }

        try {
            const data = await request('GET', '/api/overview');
            const alive = Boolean(data.worker && data.worker.alive);

            workerDot.className = 'relative inline-flex h-2 w-2 rounded-full ' + (alive ? 'bg-emerald-400' : 'bg-rose-500');
            workerLabel.textContent = alive ? 'Worker online' : 'Worker offline';
            workerLabel.style.color = alive ? '' : 'rgb(251 113 133)';
        } catch (error) {
            workerDot.className = 'relative inline-flex h-2 w-2 rounded-full bg-rose-500';
            workerLabel.textContent = 'Worker offline';
            workerLabel.style.color = 'rgb(251 113 133)';
        }
    }

    if (workerDot) {
        refreshWorker();
        window.setInterval(refreshWorker, 60000);
    }

    // Watch toggles on the candidate lists ----------------------------------
    document.querySelectorAll('.watch-toggle').forEach(function (button) {
        button.addEventListener('click', async function () {
            const domain = button.getAttribute('data-domain');
            const watched = button.getAttribute('data-watched') === '1';

            try {
                if (watched) {
                    await request('DELETE', '/watchlist/' + encodeURIComponent(domain));
                } else {
                    await request('POST', '/watchlist', { domain: domain, status: 'watching' });
                }

                button.setAttribute('data-watched', watched ? '0' : '1');
                button.textContent = watched ? 'Watch' : 'Watching';
                button.classList.toggle('border-sky-400', !watched);
                button.classList.toggle('text-sky-300', !watched);
                button.classList.toggle('bg-sky-500/10', !watched);
            } catch (error) {
                window.alert(error.message);
            }
        });
    });

    // Watchlist page ---------------------------------------------------------
    const watchlistForm = document.getElementById('watchlist-form');
    const watchlistMessage = document.getElementById('watchlist-message');

    function say(message, isError) {
        if (!watchlistMessage) {
            return;
        }

        watchlistMessage.textContent = message;
        watchlistMessage.className = 'text-sm ' + (isError ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400');
    }

    watchlistForm?.addEventListener('submit', async function (event) {
        event.preventDefault();

        const domain = watchlistForm.querySelector('#domain');
        const status = watchlistForm.querySelector('#status');

        try {
            await request('POST', '/watchlist', { domain: domain.value, status: status.value });
            say('Added ' + domain.value + '.', false);
            domain.value = '';
            window.setTimeout(function () { window.location.reload(); }, 400);
        } catch (error) {
            say(error.message, true);
        }
    });

    document.querySelectorAll('[data-watch-entry]').forEach(function (entry) {
        const domain = entry.getAttribute('data-domain');

        entry.querySelector('.watch-remove')?.addEventListener('click', async function () {
            try {
                await request('DELETE', '/watchlist/' + encodeURIComponent(domain));
                entry.remove();
            } catch (error) {
                window.alert(error.message);
            }
        });

        entry.querySelector('.watch-status')?.addEventListener('change', async function (event) {
            try {
                await request('PUT', '/watchlist/' + encodeURIComponent(domain), { status: event.target.value });
                say('Updated ' + domain + '.', false);
            } catch (error) {
                say(error.message, true);
            }
        });

        entry.querySelector('[data-note-form]')?.addEventListener('submit', async function (event) {
            event.preventDefault();

            const input = event.target.querySelector('input[name="body"]');

            try {
                await request('POST', '/watchlist/' + encodeURIComponent(domain) + '/notes', { body: input.value });
                input.value = '';
                say('Note saved.', false);
                window.setTimeout(function () { window.location.reload(); }, 400);
            } catch (error) {
                say(error.message, true);
            }
        });
    });

    // Zone search ------------------------------------------------------------
    const zoneSearchForm = document.getElementById('zone-search-form');
    const zoneResults = document.querySelector('[data-search-results]');

    if (zoneSearchForm && zoneResults) {
        let timer;
        let latestRequest = 0;

        const swapResults = async function () {
            const params = new URLSearchParams(new FormData(zoneSearchForm));
            const url = '/search?' + params.toString();
            const requestId = ++latestRequest;

            zoneResults.classList.add('opacity-60');

            try {
                const response = await fetch(url, {
                    headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error('Search failed (' + response.status + ')');
                }

                const html = await response.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const fresh = doc.querySelector('[data-search-results]');

                if (fresh && requestId === latestRequest) {
                    zoneResults.innerHTML = fresh.innerHTML;
                    window.history.replaceState(null, '', url);
                }
            } catch (error) {
                window.console.error(error);
            } finally {
                if (requestId === latestRequest) {
                    zoneResults.classList.remove('opacity-60');
                }
            }
        };

        zoneSearchForm.querySelector('#q')?.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(swapResults, 400);
        });

        zoneSearchForm.querySelectorAll('select, input[type="number"], input[type="checkbox"]').forEach(function (field) {
            field.addEventListener('change', swapResults);
        });

        zoneSearchForm.addEventListener('submit', function (event) {
            event.preventDefault();
            window.clearTimeout(timer);
            swapResults();
        });
    }
})();
