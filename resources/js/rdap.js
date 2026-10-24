const CACHE_PREFIX = 'dropwatch:rdap:v1:';
const CACHE_TTL_MS = 24 * 60 * 60 * 1000;
const REQUEST_GAP_MS = 1000;
const FETCH_TIMEOUT_MS = 15000;

export const MAX_SELECTED = 20;

const TONES = {
    danger: { background: 'rgb(244 63 94 / 0.16)', color: 'rgb(251 113 133)' },
    warning: { background: 'rgb(245 158 11 / 0.16)', color: 'rgb(251 191 36)' },
    info: { background: 'rgb(14 165 233 / 0.16)', color: 'rgb(56 189 248)' },
    success: { background: 'rgb(16 185 129 / 0.16)', color: 'rgb(52 211 153)' },
    neutral: { background: 'var(--surface-2)', color: 'var(--muted)' },
};

const HANDLED_STATUSES = [
    'pending delete',
    'redemption period',
    'pending restore',
    'auto renew period',
    'client hold',
    'server hold',
];

function baseResult(source, now) {
    return {
        key: 'unreadable',
        label: 'Could not read, open raw',
        tone: 'warning',
        saveable: false,
        expiry: null,
        lastChanged: null,
        registrar: null,
        estimatedDrop: null,
        extraStatuses: [],
        message: null,
        retryAfterSeconds: null,
        checkedAt: new Date(now).toISOString(),
        source: source,
    };
}

export function dateOnly(value) {
    if (typeof value !== 'string') {
        return null;
    }

    const match = value.match(/^(\d{4}-\d{2}-\d{2})/);

    return match ? match[1] : null;
}

function addDays(date, days) {
    const parsed = new Date(date + 'T00:00:00Z');

    if (Number.isNaN(parsed.getTime())) {
        return null;
    }

    parsed.setUTCDate(parsed.getUTCDate() + days);

    return parsed.toISOString().slice(0, 10);
}

function eventDate(payload, action) {
    const events = Array.isArray(payload.events) ? payload.events : [];

    for (const event of events) {
        if (
            event
            && typeof event.eventAction === 'string'
            && event.eventAction.toLowerCase() === action
        ) {
            return dateOnly(event.eventDate);
        }
    }

    return null;
}

function vcardName(entity) {
    const vcard = entity && entity.vcardArray;

    if (!Array.isArray(vcard) || !Array.isArray(vcard[1])) {
        return null;
    }

    for (const entry of vcard[1]) {
        if (
            Array.isArray(entry)
            && entry[0] === 'fn'
            && typeof entry[3] === 'string'
            && entry[3].trim() !== ''
        ) {
            return entry[3].trim();
        }
    }

    return null;
}

function registrarName(entities) {
    if (!Array.isArray(entities)) {
        return null;
    }

    for (const entity of entities) {
        if (!entity || typeof entity !== 'object') {
            continue;
        }

        const roles = Array.isArray(entity.roles)
            ? entity.roles.map(function (role) { return String(role).toLowerCase(); })
            : [];

        if (roles.includes('registrar')) {
            const name = vcardName(entity);

            if (name) {
                return name;
            }
        }

        const nested = registrarName(entity.entities);

        if (nested) {
            return nested;
        }
    }

    return null;
}

function statusesOf(payload) {
    if (!Array.isArray(payload.status)) {
        return [];
    }

    return payload.status
        .filter(function (status) { return typeof status === 'string'; })
        .map(function (status) { return status.trim().toLowerCase(); })
        .filter(function (status) { return status !== ''; });
}

/**
 * Turn an RDAP domain object into a badge and a details line. The order of
 * the checks is deliberate: pending delete beats redemption, holds beat an
 * ordinary renewed record.
 */
export function parseRdap(payload, options) {
    const settings = options || {};
    const source = settings.source || 'rdap';
    const now = settings.now || new Date();
    const result = baseResult(source, now);

    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
        result.message = 'The registry returned an unreadable record.';

        return result;
    }

    const statuses = statusesOf(payload);
    const has = function () {
        return Array.prototype.slice.call(arguments).some(function (name) {
            return statuses.includes(name);
        });
    };

    const expiry = eventDate(payload, 'expiration');
    const lastChanged = eventDate(payload, 'last changed');
    const today = new Date(now).toISOString().slice(0, 10);

    result.expiry = expiry;
    result.lastChanged = lastChanged;
    result.registrar = registrarName(payload.entities);
    result.extraStatuses = statuses.filter(function (status) {
        return !HANDLED_STATUSES.includes(status);
    });

    if (has('pending delete')) {
        result.key = 'pending_delete';
        result.label = 'Pending delete (drops in about 5 days)';
        result.tone = 'danger';
        result.saveable = true;
    } else if (has('redemption period')) {
        result.key = 'redemption';
        result.label = 'In redemption';
        result.tone = 'warning';
        result.saveable = true;
    } else if (has('pending restore')) {
        result.key = 'pending_restore';
        result.label = 'Being restored';
        result.tone = 'info';
        result.saveable = true;
    } else if (has('auto renew period')) {
        result.key = 'auto_renew';
        result.label = 'Grace period';
        result.tone = 'info';
        result.saveable = true;
    } else if (has('client hold', 'server hold')) {
        result.key = 'on_hold';
        result.label = 'On hold';
        result.tone = 'warning';
        result.saveable = true;
    } else if (expiry !== null && expiry < today) {
        result.key = 'expired';
        result.label = 'Expired, in grace';
        result.tone = 'warning';
        result.saveable = true;
    } else {
        result.key = 'renewed';
        result.label = expiry === null ? 'Renewed' : 'Renewed to ' + expiry;
        result.tone = 'success';
        result.saveable = true;
    }

    if (lastChanged !== null && (result.key === 'pending_delete' || result.key === 'redemption')) {
        const days = result.key === 'pending_delete' ? 5 : 35;
        const date = addDays(lastChanged, days);

        if (date !== null) {
            result.estimatedDrop = {
                date: date,
                days: days,
                basis: 'last changed + ' + days + ' days',
            };
        }
    }

    return result;
}

export function retryAfterSeconds(value, now) {
    if (value === null || value === undefined || String(value).trim() === '') {
        return null;
    }

    const text = String(value).trim();

    if (/^\d+$/.test(text)) {
        return Number(text);
    }

    const date = new Date(text);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const current = new Date(now || new Date()).getTime();

    return Math.max(0, Math.ceil((date.getTime() - current) / 1000));
}

function notFound(source, now) {
    const result = baseResult(source, now);

    result.saveable = false;

    if (source === 'com') {
        result.key = 'not_registered';
        result.label = 'Not registered';
        result.tone = 'success';
    } else {
        result.key = 'not_found';
        result.label = 'Not found / unknown';
        result.tone = 'neutral';
    }

    return result;
}

function rateLimited(source, retryAfter, now) {
    const result = baseResult(source, now);
    const seconds = retryAfterSeconds(retryAfter, now);

    result.key = 'rate_limited';
    result.label = 'Rate limited, wait a few minutes';
    result.tone = 'warning';
    result.retryAfterSeconds = seconds;
    result.message = seconds === null
        ? 'Wait a few minutes before checking again.'
        : 'The registry asked to wait ' + seconds + ' second' + (seconds === 1 ? '' : 's') + ' before the next check.';

    return result;
}

/**
 * Handle the HTTP result. Nothing here retries: a 429 is reported and the
 * caller decides when to try again.
 */
export function readResponse(response, options) {
    const input = response || {};
    const settings = options || {};
    const source = input.source || settings.source || 'rdap';
    const now = settings.now || new Date();
    const status = Number(input.status);

    if (status === 429) {
        return rateLimited(source, input.retryAfter ?? null, now);
    }

    if (status === 404) {
        return notFound(source, now);
    }

    if (status !== 200) {
        const result = baseResult(source, now);

        result.message = status > 0
            ? 'The registry answered with HTTP ' + status + '.'
            : 'The check could not be completed in this browser.';

        return result;
    }

    let payload = input.body;

    if (typeof payload === 'string') {
        try {
            payload = JSON.parse(payload);
        } catch (error) {
            const result = baseResult(source, now);

            result.message = 'The registry returned an unreadable record.';

            return result;
        }
    }

    return parseRdap(payload, { source: source, now: now });
}

function cacheKey(domain) {
    return CACHE_PREFIX + String(domain).toLowerCase();
}

function readCache(domain) {
    try {
        const raw = window.localStorage.getItem(cacheKey(domain));

        if (!raw) {
            return null;
        }

        const entry = JSON.parse(raw);

        if (
            !entry
            || typeof entry.savedAt !== 'number'
            || !entry.result
            || typeof entry.result.label !== 'string'
        ) {
            return null;
        }

        if (Date.now() - entry.savedAt > CACHE_TTL_MS) {
            window.localStorage.removeItem(cacheKey(domain));

            return null;
        }

        return entry.result;
    } catch (error) {
        return null;
    }
}

function writeCache(domain, result) {
    if (!result || result.key === 'rate_limited' || result.key === 'unreadable') {
        return;
    }

    try {
        window.localStorage.setItem(cacheKey(domain), JSON.stringify({
            savedAt: Date.now(),
            result: result,
        }));
    } catch (error) {
        // Private mode or a full quota: the check still works, it is just
        // not remembered.
    }
}

async function fetchStatus(url, source) {
    const controller = new AbortController();
    const timer = window.setTimeout(function () { controller.abort(); }, FETCH_TIMEOUT_MS);

    try {
        const response = await fetch(url, {
            method: 'GET',
            mode: 'cors',
            credentials: 'omit',
            cache: 'no-store',
            redirect: 'follow',
            headers: { Accept: 'application/rdap+json, application/json' },
            signal: controller.signal,
        });

        let body = null;

        if (response.status === 200) {
            body = await response.text();
        }

        return readResponse({
            status: response.status,
            body: body,
            retryAfter: response.headers.get('Retry-After'),
            source: source,
        });
    } catch (error) {
        return readResponse({ status: 0, source: source });
    } finally {
        window.clearTimeout(timer);
    }
}

async function checkDomain(domain, url, source, options) {
    const settings = options || {};

    if (!settings.force) {
        const cached = readCache(domain);

        if (cached) {
            return { result: cached, cached: true, requested: false };
        }
    }

    const result = await fetchStatus(url, source);

    writeCache(domain, result);

    return { result: result, cached: false, requested: true };
}

function paintBadge(badge, result) {
    const tone = TONES[result.tone] || TONES.neutral;

    badge.hidden = false;
    badge.textContent = result.label;
    badge.style.background = tone.background;
    badge.style.color = tone.color;
}

function formatCheckedAt(value) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function detailsLine(result) {
    const parts = [
        'Expiry ' + (result.expiry || '—'),
        'Last changed ' + (result.lastChanged || '—'),
        'Registrar ' + (result.registrar || '—'),
    ];

    if (Array.isArray(result.extraStatuses) && result.extraStatuses.length > 0) {
        parts.push('Other status: ' + result.extraStatuses.join(', '));
    }

    return parts.join(' · ');
}

function setCheckButtons(scope, label) {
    scope.querySelectorAll('[data-rdap-check]').forEach(function (button) {
        button.textContent = label;
    });
}

function renderResult(scope, result, options) {
    const settings = options || {};
    const container = scope.querySelector('[data-rdap-result]');

    if (!container) {
        return;
    }

    scope.__rdapResult = result;
    scope.dataset.rdapHasResult = '1';
    container.hidden = false;

    const badge = container.querySelector('[data-rdap-badge]');
    const checked = container.querySelector('[data-rdap-checked]');
    const details = container.querySelector('[data-rdap-details]');
    const estimate = container.querySelector('[data-rdap-estimate]');
    const message = container.querySelector('[data-rdap-message]');
    const save = container.querySelector('[data-rdap-save]');
    const saved = container.querySelector('[data-rdap-saved]');

    if (badge) {
        paintBadge(badge, result);
    }

    if (checked) {
        checked.textContent = 'Checked ' + formatCheckedAt(result.checkedAt) + (settings.cached ? ' (cached)' : '');
    }

    if (details) {
        details.textContent = detailsLine(result);
    }

    if (estimate) {
        estimate.hidden = result.estimatedDrop === null;

        if (result.estimatedDrop !== null) {
            estimate.textContent = 'Estimated drop ' + result.estimatedDrop.date
                + ' (' + result.estimatedDrop.basis + ', estimate)';
            estimate.style.color = TONES.warning.color;
        }
    }

    if (message) {
        message.hidden = !result.message;
        message.textContent = result.message || '';
        message.style.color = TONES.warning.color;
    }

    if (save) {
        save.hidden = !result.saveable;
        save.disabled = false;
        save.textContent = 'Save status';
    }

    if (saved) {
        saved.textContent = '';
    }

    setCheckButtons(scope, 'Recheck');
}

function updateDomainBadges(domain, result, cached) {
    document.querySelectorAll('[data-rdap]').forEach(function (scope) {
        if (scope.dataset.domain !== domain) {
            return;
        }

        if (scope.dataset.rdapMode === 'compact') {
            const badge = scope.querySelector('[data-rdap-badge]');

            if (badge) {
                paintBadge(badge, result);
            }

            return;
        }

        renderResult(scope, result, { cached: cached === true });
    });
}

function dialogFor(scope) {
    const dialog = document.getElementById('rdap-dialog');

    if (!dialog) {
        return null;
    }

    dialog.dataset.domain = scope.dataset.domain;
    dialog.dataset.url = scope.dataset.url;
    dialog.dataset.source = scope.dataset.source;
    delete dialog.dataset.rdapHasResult;

    const title = dialog.querySelector('[data-rdap-dialog-domain]');
    const raw = dialog.querySelector('[data-rdap-dialog-raw]');
    const intro = dialog.querySelector('[data-rdap-dialog-status]');
    const result = dialog.querySelector('[data-rdap-result]');
    const saved = dialog.querySelector('[data-rdap-saved]');

    if (title) {
        title.textContent = scope.dataset.domain;
    }

    if (raw) {
        raw.href = scope.dataset.url;
    }

    if (intro) {
        intro.hidden = false;
        intro.textContent = 'Ready to check.';
    }

    if (result) {
        result.hidden = true;
    }

    if (saved) {
        saved.textContent = '';
    }

    setCheckButtons(dialog, 'Check status');

    if (typeof dialog.showModal === 'function') {
        dialog.showModal();
    } else {
        dialog.setAttribute('open', '');
    }

    return dialog;
}

async function performCheck(scope, options) {
    const settings = options || {};

    if (!scope || !scope.dataset.domain || !scope.dataset.url || scope.dataset.rdapBusy === '1') {
        return null;
    }

    scope.dataset.rdapBusy = '1';

    const buttons = scope.querySelectorAll('[data-rdap-check]');
    const intro = scope.querySelector('[data-rdap-dialog-status]');

    buttons.forEach(function (button) {
        button.disabled = true;
        button.textContent = 'Checking…';
    });

    if (intro) {
        intro.hidden = false;
        intro.textContent = 'Checking the registry…';
    }

    const outcome = await checkDomain(
        scope.dataset.domain,
        scope.dataset.url,
        scope.dataset.source,
        { force: settings.force === true },
    );

    renderResult(scope, outcome.result, { cached: outcome.cached });
    updateDomainBadges(scope.dataset.domain, outcome.result, outcome.cached);

    if (intro) {
        intro.hidden = true;
    }

    buttons.forEach(function (button) {
        button.disabled = false;
        button.textContent = 'Recheck';
    });

    delete scope.dataset.rdapBusy;

    return outcome;
}

async function saveStatus(button) {
    const scope = button.closest('[data-rdap]');
    const result = scope ? scope.__rdapResult : null;
    const saved = scope ? scope.querySelector('[data-rdap-saved]') : null;

    if (!scope || !result || !result.saveable) {
        return;
    }

    button.disabled = true;
    button.textContent = 'Saving…';

    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    try {
        const response = await fetch('/domain-status', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                domain: scope.dataset.domain,
                status: result.key,
                status_label: result.label,
                expiry: result.expiry,
                checked_at: result.checkedAt,
            }),
        });

        if (!response.ok) {
            let message = 'Could not save the status.';

            try {
                const payload = await response.json();
                message = payload.message || payload.error || message;
            } catch (error) {
                // Keep the default message.
            }

            throw new Error(message);
        }

        button.hidden = true;

        if (saved) {
            saved.style.color = TONES.success.color;
            saved.textContent = result.key === 'on_hold' || result.key === 'renewed'
                ? 'Saved. Hidden from the Daily and Overview lists.'
                : 'Saved.';
        }
    } catch (error) {
        button.disabled = false;
        button.textContent = 'Save status';

        if (saved) {
            saved.style.color = TONES.danger.color;
            saved.textContent = error.message;
        }
    }
}

function selectedBoxes() {
    return Array.from(document.querySelectorAll('[data-rdap-select]'));
}

function syncSelection(changed) {
    let boxes = selectedBoxes();
    let checked = boxes.filter(function (box) { return box.checked; });

    if (changed && changed.checked && checked.length > MAX_SELECTED) {
        changed.checked = false;
        boxes = selectedBoxes();
        checked = boxes.filter(function (box) { return box.checked; });
    }

    boxes.forEach(function (box) {
        box.disabled = !box.checked && checked.length >= MAX_SELECTED;
    });

    const label = checked.length + ' selected' + (checked.length >= MAX_SELECTED ? ' (max)' : '');

    document.querySelectorAll('[data-rdap-selection-count]').forEach(function (element) {
        element.textContent = label;
    });

    document.querySelectorAll('[data-rdap-check-selected]').forEach(function (button) {
        button.disabled = checked.length === 0 || button.dataset.rdapRunning === '1';
    });
}

function setSelectionMessage(message) {
    document.querySelectorAll('[data-rdap-selection-message]').forEach(function (element) {
        element.textContent = message;
    });
}

function sleep(milliseconds) {
    return new Promise(function (resolve) {
        window.setTimeout(resolve, milliseconds);
    });
}

async function checkSelected(button) {
    if (button.dataset.rdapRunning === '1') {
        return;
    }

    const selected = selectedBoxes()
        .filter(function (box) { return box.checked; })
        .slice(0, MAX_SELECTED);

    if (selected.length === 0) {
        return;
    }

    button.dataset.rdapRunning = '1';
    button.disabled = true;
    button.textContent = 'Checking…';

    let done = 0;
    let rateLimited = null;

    for (const box of selected) {
        const domain = box.value;
        const scope = Array.from(document.querySelectorAll('[data-rdap][data-rdap-mode="compact"]'))
            .find(function (candidate) { return candidate.dataset.domain === domain; });

        if (!scope) {
            done += 1;

            continue;
        }

        setSelectionMessage('Checking ' + (done + 1) + ' of ' + selected.length + '…');

        const outcome = await checkDomain(domain, scope.dataset.url, scope.dataset.source);
        updateDomainBadges(domain, outcome.result, outcome.cached);
        done += 1;

        if (outcome.result.key === 'rate_limited') {
            rateLimited = domain;

            break;
        }

        if (outcome.requested && done < selected.length) {
            await sleep(REQUEST_GAP_MS);
        }
    }

    setSelectionMessage(rateLimited === null
        ? 'Checked ' + done + ' name' + (done === 1 ? '' : 's') + '.'
        : 'Rate limited on ' + rateLimited + '. Stopped after ' + done + ' check' + (done === 1 ? '' : 's') + '; wait a few minutes.');

    button.textContent = 'Check selected';
    delete button.dataset.rdapRunning;
    syncSelection();
}

export function initRdap() {
    if (typeof document === 'undefined') {
        return;
    }

    document.querySelectorAll('[data-rdap][data-rdap-mode="panel"]').forEach(function (scope) {
        const cached = readCache(scope.dataset.domain);

        if (cached) {
            renderResult(scope, cached, { cached: true });
            updateDomainBadges(scope.dataset.domain, cached, true);
        }
    });

    document.addEventListener('change', function (event) {
        const box = event.target.closest('[data-rdap-select]');

        if (box) {
            syncSelection(box);
        }
    });

    document.addEventListener('click', function (event) {
        const save = event.target.closest('[data-rdap-save]');

        if (save) {
            saveStatus(save);

            return;
        }

        const selected = event.target.closest('[data-rdap-check-selected]');

        if (selected) {
            checkSelected(selected);

            return;
        }

        const check = event.target.closest('[data-rdap-check]');

        if (!check) {
            return;
        }

        const scope = check.closest('[data-rdap]');

        if (!scope) {
            return;
        }

        if (scope.dataset.rdapMode === 'compact') {
            const dialog = dialogFor(scope);

            if (dialog) {
                performCheck(dialog, { force: false });
            }

            return;
        }

        performCheck(scope, { force: scope.dataset.rdapHasResult === '1' });
    });

    syncSelection();
}
