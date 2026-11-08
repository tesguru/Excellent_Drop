import { initRdap, refreshRdap } from './rdap.js';

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

    // Live lists -------------------------------------------------------------
    // Filters, pagination and the page mover swap one region's HTML over
    // AJAX, so a list never reloads the document. The server answers an
    // X-Requested-With request with that region's partial only.
    const regions = new Map();

    function liveRegion(selector, externalForm) {
        const region = document.querySelector(selector);

        if (!region) {
            return;
        }

        let latest = 0;
        let timer;

        // A form outside the region is not re-rendered by a swap, so mirror
        // the clicked URL back into it.
        const syncForm = function (href) {
            if (!externalForm) {
                return;
            }

            const params = new URL(href, window.location.origin).searchParams;

            externalForm.querySelectorAll('input[name], select[name]').forEach(function (field) {
                if (field.type === 'checkbox') {
                    field.checked = params.has(field.name);
                } else if (field.multiple) {
                    const values = params.getAll(field.name);

                    Array.from(field.options).forEach(function (option) {
                        option.selected = values.includes(option.value);
                    });
                } else if (field.tagName === 'SELECT') {
                    field.value = params.get(field.name) ?? (field.options[0] ? field.options[0].value : '');
                } else {
                    field.value = params.get(field.name) ?? '';
                }
            });
        };

        const load = async function (url) {
            const requestId = ++latest;

            region.classList.add('opacity-60');

            try {
                const response = await fetch(url, {
                    headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error('Request failed (' + response.status + ')');
                }

                const html = await response.text();

                if (requestId !== latest) {
                    return;
                }

                region.innerHTML = html;
                window.history.replaceState(null, '', url);
                refreshRdap();
            } catch (error) {
                window.console.error(error);
            } finally {
                if (requestId === latest) {
                    region.classList.remove('opacity-60');
                }
            }
        };

        regions.set(selector, {
            refresh: function () {
                load(window.location.pathname + window.location.search);
            },
        });

        const onSubmit = function (event) {
            const form = event.target;

            // Notes post over their own handler; they are not navigation.
            if (!(form instanceof HTMLFormElement)
                || form.method.toLowerCase() !== 'get'
                || form.hasAttribute('data-note-form')) {
                return;
            }

            event.preventDefault();
            window.clearTimeout(timer);

            const params = new URLSearchParams(new FormData(form));
            const action = (form.getAttribute('action') || window.location.pathname).split('?')[0];

            load(action + '?' + params.toString());
        };

        const onClick = function (event) {
            const link = event.target.closest ? event.target.closest('a[href]') : null;

            if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
                return;
            }

            const url = new URL(link.href, window.location.origin);

            if (url.origin !== window.location.origin || url.pathname !== window.location.pathname) {
                return;
            }

            event.preventDefault();
            syncForm(url.href);
            load(url.pathname + url.search);
        };

        const submitField = function (field) {
            window.clearTimeout(timer);

            if (field.form) {
                field.form.requestSubmit();
            }
        };

        const onChange = function (event) {
            const field = event.target;

            if (field.matches('[data-auto-submit]') && field.type !== 'search' && field.type !== 'text') {
                submitField(field);
            }
        };

        const onInput = function (event) {
            const field = event.target;

            if (!field.matches('input[data-auto-submit][type="search"], input[data-auto-submit][type="text"]')) {
                return;
            }

            window.clearTimeout(timer);
            timer = window.setTimeout(function () { submitField(field); }, 400);
        };

        [region, externalForm].forEach(function (scope) {
            if (!scope) {
                return;
            }

            scope.addEventListener('submit', onSubmit);
            scope.addEventListener('change', onChange);
            scope.addEventListener('input', onInput);
        });

        region.addEventListener('click', onClick);
    }

    function refreshRegion(selector) {
        regions.get(selector)?.refresh();
    }

    liveRegion('[data-overview-list]');
    liveRegion('[data-daily-list]');
    liveRegion('[data-watchlist-list]');
    liveRegion('[data-search-results]', document.getElementById('zone-search-form'));

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

    // Delegated so the buttons keep working after a list swaps its HTML.
    document.addEventListener('click', async function (event) {
        const button = event.target.closest ? event.target.closest('.watch-toggle') : null;

        if (!button) {
            return;
        }

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

    // Candidate detail modals ------------------------------------------------
    document.addEventListener('click', function (event) {
        const target = event.target;
        const trigger = target.closest ? target.closest('[data-modal-open]') : null;

        if (trigger) {
            const dialog = document.getElementById(trigger.getAttribute('data-modal-open'));

            if (!dialog) {
                return;
            }

            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }

            return;
        }

        const close = target.closest ? target.closest('[data-modal-close]') : null;

        if (close) {
            const dialog = close.closest('dialog[data-modal]');

            if (!dialog) {
                return;
            }

            if (typeof dialog.close === 'function') {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }

            return;
        }

        // A click on the backdrop lands on the dialog element itself.
        if (target.tagName !== 'DIALOG' || !target.hasAttribute('data-modal')) {
            return;
        }

        const rect = target.getBoundingClientRect();
        const inside = event.clientX >= rect.left && event.clientX <= rect.right
            && event.clientY >= rect.top && event.clientY <= rect.bottom;

        if (!inside && typeof target.close === 'function') {
            target.close();
        }
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

    document.addEventListener('click', async function (event) {
        const button = event.target.closest ? event.target.closest('[data-copy]') : null;

        if (!button) {
            return;
        }

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
            refreshRegion('[data-watchlist-list]');
        } catch (error) {
            say(error.message, true);
        }
    });

    document.addEventListener('click', async function (event) {
        const button = event.target.closest ? event.target.closest('.watch-remove') : null;

        if (!button) {
            return;
        }

        const entry = button.closest('[data-watch-entry]');
        const domain = entry ? entry.getAttribute('data-domain') : '';

        if (!window.confirm('Remove ' + domain + ' from the watchlist?')) {
            return;
        }

        try {
            await request('DELETE', '/watchlist/' + encodeURIComponent(domain));
            say('Removed ' + domain + '.', false);
            refreshRegion('[data-watchlist-list]');
        } catch (error) {
            window.alert(error.message);
        }
    });

    document.addEventListener('change', async function (event) {
        const select = event.target.closest ? event.target.closest('.watch-status') : null;

        if (!select) {
            return;
        }

        const entry = select.closest('[data-watch-entry]');
        const domain = entry ? entry.getAttribute('data-domain') : '';
        const status = select.value;

        try {
            await request('PUT', '/watchlist/' + encodeURIComponent(domain), { status: status });

            const chip = entry ? entry.querySelector('[data-status-chip]') : null;

            if (chip) {
                chip.textContent = status.charAt(0).toUpperCase() + status.slice(1);
            }

            say('Updated ' + domain + '.', false);
        } catch (error) {
            say(error.message, true);
        }
    });

    document.addEventListener('submit', async function (event) {
        const form = event.target.closest ? event.target.closest('[data-note-form]') : null;

        if (!form) {
            return;
        }

        event.preventDefault();

        const entry = form.closest('[data-watch-entry]');
        const domain = entry ? entry.getAttribute('data-domain') : '';
        const input = form.querySelector('input[name="body"]');

        try {
            await request('POST', '/watchlist/' + encodeURIComponent(domain) + '/notes', { body: input.value });
            input.value = '';
            say('Note saved.', false);
            refreshRegion('[data-watchlist-list]');
        } catch (error) {
            say(error.message, true);
        }
    });
})();
