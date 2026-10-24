import { initRdap } from './rdap.js';

(function () {
    'use strict';

    initRdap();

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
    function syncWatch(domain, watched) {
        document.querySelectorAll('.watch-toggle').forEach(function (button) {
            if (button.getAttribute('data-domain') !== domain) {
                return;
            }

            button.setAttribute('data-watched', watched ? '1' : '0');
            button.textContent = watched ? 'Watching' : 'Watch';
            button.classList.toggle('border-transparent', watched);
            button.classList.toggle('border-[color:var(--border)]', !watched);
            button.classList.toggle('muted', !watched);

            if (watched) {
                button.style.background = 'rgb(var(--glow) / 0.14)';
                button.style.color = 'var(--accent)';
            } else {
                button.style.background = '';
                button.style.color = '';
            }
        });
    }

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

                syncWatch(domain, !watched);
            } catch (error) {
                window.alert(error.message);
            }
        });
    });

    // Candidate detail modals ------------------------------------------------
    document.querySelectorAll('[data-modal-open]').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            const dialog = document.getElementById(trigger.getAttribute('data-modal-open'));

            if (!dialog) {
                return;
            }

            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }
        });
    });

    document.querySelectorAll('dialog[data-modal]').forEach(function (dialog) {
        dialog.querySelectorAll('[data-modal-close]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (typeof dialog.close === 'function') {
                    dialog.close();
                } else {
                    dialog.removeAttribute('open');
                }
            });
        });

        dialog.addEventListener('click', function (event) {
            if (event.target !== dialog) {
                return;
            }

            const rect = dialog.getBoundingClientRect();
            const inside = event.clientX >= rect.left && event.clientX <= rect.right
                && event.clientY >= rect.top && event.clientY <= rect.bottom;

            if (!inside && typeof dialog.close === 'function') {
                dialog.close();
            }
        });
    });

    // Copy buttons -----------------------------------------------------------
    function legacyCopy(value) {
        const field = document.createElement('textarea');
        field.value = value;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();

        let copied = false;

        try {
            copied = document.execCommand('copy');
        } catch (error) {
            copied = false;
        }

        field.remove();

        return copied;
    }

    function copyText(value) {
        if (legacyCopy(value)) {
            return Promise.resolve(true);
        }

        if (!navigator.clipboard || !window.isSecureContext) {
            return Promise.resolve(false);
        }

        // The clipboard prompt can hang in embedded browsers, so never let the
        // button wait on it forever.
        return new Promise(function (resolve) {
            const timer = window.setTimeout(function () { resolve(false); }, 800);

            navigator.clipboard.writeText(value).then(
                function () {
                    window.clearTimeout(timer);
                    resolve(true);
                },
                function () {
                    window.clearTimeout(timer);
                    resolve(false);
                },
            );
        });
    }

    document.querySelectorAll('[data-copy]').forEach(function (button) {
        button.addEventListener('click', async function () {
            const value = button.getAttribute('data-copy') || '';
            const label = button.querySelector('[data-copy-label]');
            const original = label ? label.textContent : button.textContent;

            const sayCopied = function (text) {
                if (label) {
                    label.textContent = text;
                } else {
                    button.textContent = text;
                }
            };

            if (!(await copyText(value))) {
                window.alert('Could not copy the domain.');

                return;
            }

            sayCopied('Copied');
            window.setTimeout(function () { sayCopied(original); }, 1500);
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
            if (!window.confirm('Remove ' + domain + ' from the watchlist?')) {
                return;
            }

            try {
                await request('DELETE', '/watchlist/' + encodeURIComponent(domain));
                say('Removed ' + domain + '.', false);
                window.setTimeout(function () { window.location.reload(); }, 300);
            } catch (error) {
                window.alert(error.message);
            }
        });

        entry.querySelector('.watch-status')?.addEventListener('change', async function (event) {
            const status = event.target.value;

            try {
                await request('PUT', '/watchlist/' + encodeURIComponent(domain), { status: status });

                const chip = entry.querySelector('[data-status-chip]');

                if (chip) {
                    chip.textContent = status.charAt(0).toUpperCase() + status.slice(1);
                }

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
