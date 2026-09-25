/**
 * SUBandL global widget — injected into every page by InjectWidget.
 *
 *   • edge tab → offcanvas panel: payment reminder card (only while an amount
 *     is due), version / update status, backup status
 *   • modal whenever a newer version is available, to apply it right there
 *
 * Framework-agnostic (plain DOM), so it behaves the same inside Blade, Vue or
 * React hosts. Follows Inertia page visits via the `inertia:navigate` event.
 * Host pages can open the panel with `data-subandl-open` or SUBandLWidget.open().
 */
import { createSubandl } from './subandl.js';

const script = document.getElementById('subandl-widget');
const cfg = (() => {
    try {
        return JSON.parse(script?.dataset.config || '{}');
    } catch (e) {
        return {};
    }
})();

const api = createSubandl({ base: cfg.base || '' });
const REFRESH_MS = 5 * 60 * 1000;
const DUE_SNOOZE_KEY = 'subandl:due-snooze-until';
const UPDATE_SNOOZE_KEY = 'subandl:update-snooze:';

let state = null; // last /subandl/widget payload, null while signed out
let fetchedAt = 0;
let busy = null; // 'update' | 'backup' while a flow runs
let panelOpen = false;
let modalDismissedFor = null;

/* ------------------------------------------------------------------ utils */

const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const store = {
    get(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (e) {
            return null;
        }
    },
    set(key, value) {
        try {
            window.localStorage.setItem(key, String(value));
        } catch (e) {
            // Storage blocked: snoozes just won't survive a reload.
        }
    },
};

const snoozed = (key) => Number(store.get(key) || 0) > Date.now();
const snooze = (key, ms) => store.set(key, Date.now() + ms);

function money(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n.toLocaleString() : esc(value);
}

function date(value, withTime = false) {
    if (!value) return '—';
    const d = new Date(String(value).replace(' ', 'T'));
    if (isNaN(d.getTime())) return esc(value);
    const opts = { day: '2-digit', month: 'short', year: 'numeric' };
    return withTime ? d.toLocaleString(undefined, { ...opts, hour: '2-digit', minute: '2-digit' }) : d.toLocaleDateString(undefined, opts);
}

function ago(value) {
    if (!value) return 'never';
    const d = new Date(String(value).replace(' ', 'T'));
    const mins = Math.round((Date.now() - d.getTime()) / 60000);
    if (!Number.isFinite(mins)) return esc(value);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins} min ago`;
    if (mins < 48 * 60) return `${Math.round(mins / 60)} h ago`;
    return `${Math.round(mins / 1440)} days ago`;
}

function relativePath() {
    let basePath = '';
    try {
        basePath = new URL(cfg.base || '/', window.location.origin).pathname.replace(/\/+$/, '');
    } catch (e) {
        // Keep the whole pathname.
    }
    const path = window.location.pathname;
    return (path.startsWith(basePath) ? path.slice(basePath.length) : path).replace(/^\/+|\/+$/g, '');
}

// Same pattern syntax as Laravel's Request::is().
function matchesHere(patterns) {
    const path = relativePath();
    return (patterns || []).some((pattern) => {
        const p = String(pattern).replace(/^\/+|\/+$/g, '');
        const re = new RegExp('^' + p.split('*').map((s) => s.replace(/[.+?^${}()|[\]\\]/g, '\\$&')).join('.*') + '$');
        return re.test(path);
    });
}

const isHiddenHere = () => matchesHere(cfg.hiddenOn);

/* -------------------------------------------------------------------- DOM */

const icons = {
    shield: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    close: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>',
};

const root = document.createElement('div');
root.className = 'sbw';
root.hidden = true;
root.innerHTML = `
    <button type="button" class="sbw-tab" data-sbw="open" aria-label="Subscription, updates and backup">
        ${icons.shield}<span class="sbw-dot" hidden></span>
    </button>
    <div class="sbw-backdrop" data-sbw="close"></div>
    <aside class="sbw-panel" role="dialog" aria-modal="true" aria-labelledby="sbw-title" tabindex="-1">
        <header class="sbw-head">
            <h2 id="sbw-title">Subscription</h2>
            <button type="button" class="sbw-icon" data-sbw="close" aria-label="Close">${icons.close}</button>
        </header>
        <div class="sbw-body"></div>
    </aside>
    <div class="sbw-modal" hidden>
        <div class="sbw-modal-card" role="alertdialog" aria-modal="true" aria-labelledby="sbw-modal-title"></div>
    </div>`;

const $ = (sel) => root.querySelector(sel);
const panel = $('.sbw-panel');
const body = $('.sbw-body');
const modal = $('.sbw-modal');
const modalCard = $('.sbw-modal-card');

/* ---------------------------------------------------------------- render */

function renderPayment(p) {
    const info = p.payment_info || {};
    const line = (...parts) => parts.filter(Boolean).map(esc).join(', ');
    const rows = [
        info.company_name && `<strong>${esc(info.company_name)}</strong>`,
        line(info.contact_name, info.contact_title),
        line(info.bank_name, info.bank_branch),
        info.account_holder && esc(info.account_holder),
        info.account_no && `Account No. ${esc(info.account_no)}`,
        info.bkash_personal_number && `bKash: ${esc(info.bkash_personal_number)}`,
    ].filter(Boolean);

    return `
        <section class="sbw-card sbw-warn">
            <h3>Payment reminder</h3>
            ${(p.greeting || []).map((g) => `<p>${esc(g)}</p>`).join('')}
            <div class="sbw-amount">
                <span class="sbw-label">Amount due</span>
                <strong>${money(p.due_amount)} ${esc(p.currency)}</strong>
                ${p.grace_ends_at ? `<span class="sbw-muted">Please pay by ${date(p.grace_ends_at)}</span>` : ''}
            </div>
            ${p.monthly_fee != null && p.monthly_fee !== '' ? `<p class="sbw-kv"><span>Monthly fee</span><strong>${money(p.monthly_fee)} ${esc(p.currency)}</strong></p>` : ''}
            ${rows.length ? `<div class="sbw-box"><span class="sbw-label">Payment information</span>${rows.map((r) => `<p>${r}</p>`).join('')}</div>` : ''}
            ${info.closing ? `<p class="sbw-muted">${esc(info.closing)}</p>` : ''}
            <div class="sbw-actions">
                <button type="button" class="sbw-btn sbw-ghost" data-sbw="later">Remind me later</button>
                <a class="sbw-btn" href="${esc(state.urls.subscription)}">Go to subscription</a>
            </div>
        </section>`;
}

function renderUpdate(u) {
    let status;
    if (u.running || busy === 'update') status = '<span class="sbw-badge sbw-info">Updating…</span>';
    else if (u.available) status = `<span class="sbw-badge sbw-new">v${esc(u.latest_version)} available</span>`;
    else status = '<span class="sbw-badge sbw-ok">Up to date</span>';

    return `
        <section class="sbw-card">
            <h3>Software version</h3>
            <p class="sbw-kv"><span>Installed</span><strong>v${esc(state.version)}</strong></p>
            <p class="sbw-kv"><span>Status</span>${status}</p>
            ${u.support_expired ? '<p class="sbw-note sbw-bad">Update support has expired — renew it to receive updates.</p>' : ''}
            <p class="sbw-muted">Last checked: ${u.checked_at ? ago(u.checked_at) : 'not yet'}</p>
            <div class="sbw-actions">
                ${u.available && !u.running
                    ? `<button type="button" class="sbw-btn" data-sbw="update-modal" ${busy ? 'disabled' : ''}>Update to v${esc(u.latest_version)}</button>`
                    : `<button type="button" class="sbw-btn sbw-ghost" data-sbw="check-update" ${busy ? 'disabled' : ''}>Check for update</button>`}
            </div>
        </section>`;
}

function renderBackup(b) {
    let status;
    if (b.running || busy === 'backup') status = '<span class="sbw-badge sbw-info">Backing up…</span>';
    else if (b.overdue) status = '<span class="sbw-badge sbw-bad">No backup in 24 h</span>';
    else status = '<span class="sbw-badge sbw-ok">Protected</span>';

    return `
        <section class="sbw-card">
            <h3>Cloud backup</h3>
            <p class="sbw-kv"><span>Status</span>${status}</p>
            <p class="sbw-kv"><span>Last successful</span><strong>${b.last_success_at ? `${date(b.last_success_at, true)} <small>(${ago(b.last_success_at)})</small>` : 'never'}</strong></p>
            ${b.last_status === 'failed' && b.last_message ? `<p class="sbw-note sbw-bad">Last attempt failed: ${esc(b.last_message)}</p>` : ''}
            <p class="sbw-muted">${b.enabled ? 'Automatic backup is on.' : 'Automatic backup is off — a daily backup still runs.'} Next: ${date(b.next_at, true)}</p>
            <div class="sbw-actions">
                <button type="button" class="sbw-btn sbw-ghost" data-sbw="backup" ${busy ? 'disabled' : ''}>Backup now</button>
            </div>
        </section>`;
}

let flash = null; // { tone, text }

function render() {
    if (!state) return;
    body.innerHTML = [
        flash ? `<p class="sbw-flash sbw-${flash.tone}" role="status">${esc(flash.text)}</p>` : '',
        state.payment ? renderPayment(state.payment) : '',
        state.update ? renderUpdate(state.update) : '',
        state.backup ? renderBackup(state.backup) : '',
    ].join('');

    const attention = !!state.payment || !!state.update?.available || !!state.backup?.overdue;
    $('.sbw-dot').hidden = !attention;
    $('.sbw-tab').classList.toggle('sbw-attention', !!state.payment);
}

function renderModal(u, progress = null, error = null) {
    const changelog = Array.isArray(u.changelog)
        ? `<ul>${u.changelog.map((c) => `<li>${esc(c)}</li>`).join('')}</ul>`
        : u.changelog ? `<p class="sbw-pre">${esc(u.changelog)}</p>` : '';

    modalCard.innerHTML = `
        <h2 id="sbw-modal-title">New version available</h2>
        <p class="sbw-versions"><span>v${esc(state.version)}</span> → <strong>v${esc(u.latest_version)}</strong></p>
        ${changelog ? `<div class="sbw-box"><span class="sbw-label">What's new</span>${changelog}</div>` : ''}
        <p class="sbw-muted">A backup is taken before the update. Keep this page open until it finishes.</p>
        ${progress ? `<p class="sbw-flash sbw-info" role="status">${esc(progress)}</p>` : ''}
        ${error ? `<p class="sbw-flash sbw-bad" role="alert">${esc(error)}</p>` : ''}
        <div class="sbw-actions">
            ${u.force_update || busy ? '' : '<button type="button" class="sbw-btn sbw-ghost" data-sbw="update-later">Later</button>'}
            <button type="button" class="sbw-btn" data-sbw="update-run" ${busy ? 'disabled' : ''}>${busy === 'update' ? 'Updating…' : 'Update now'}</button>
        </div>`;
}

/* ------------------------------------------------------------- behaviour */

function openPanel() {
    if (!state) return;
    panelOpen = true;
    root.classList.add('sbw-open');
    panel.focus({ preventScroll: true });
}

function closePanel() {
    panelOpen = false;
    root.classList.remove('sbw-open');
    // Closing while a payment is due counts as "remind me later".
    if (state?.payment) snooze(DUE_SNOOZE_KEY, (cfg.reminderMinutes || 10) * 60000);
}

function showModal() {
    renderModal(state.update);
    modal.hidden = false;
}

function hideModal() {
    modal.hidden = true;
}

// Decides what pops up on its own after every state refresh / timer tick.
function autoPrompt() {
    if (!state || root.hidden || busy) return;

    if (state.payment && !panelOpen && !snoozed(DUE_SNOOZE_KEY)) {
        render();
        openPanel();
    }

    const u = state.update;
    if (u?.available && !u.running && !u.support_expired && modal.hidden
        && modalDismissedFor !== u.latest_version
        && (u.force_update || !snoozed(UPDATE_SNOOZE_KEY + u.latest_version))) {
        showModal();
    }
}

async function refresh(force = false) {
    if (!force && Date.now() - fetchedAt < 30000) return;
    fetchedAt = Date.now();

    const r = await api.request('GET', '/subandl/widget');
    if (r.status === 401 || r.status === 419) {
        state = null;
        applyVisibility();
        return;
    }
    if (!r.ok) return; // transient failure: keep what is on screen

    state = r.data;
    if (state.health_check_url) api.reportHealth(state.health_check_url);
    if (redirectIfLicenseUnusable()) return;
    applyVisibility();
    render();
    autoPrompt();
}

// The cached license turned unusable while this tab sat open: go where the
// middleware would send the next navigation.
function redirectIfLicenseUnusable() {
    const lic = state?.license;
    if (!lic?.needs_redirect || !lic.redirect_url || matchesHere(lic.allowed_paths)) return false;
    window.location.assign(lic.redirect_url);
    return true;
}

function applyVisibility() {
    const hasContent = !!state && (state.payment || state.update || state.backup);
    root.hidden = !hasContent || isHiddenHere();
    if (root.hidden) {
        root.classList.remove('sbw-open');
        panelOpen = false;
        hideModal();
    }
}

async function runUpdate() {
    if (busy) return;
    busy = 'update';
    renderModal(state.update, 'Starting update…');
    render();

    const result = await api.runUpdate(({ message }) => renderModal(state.update, message));
    busy = null;

    if (result.ok) {
        renderModal(state.update, (result.message || 'Update applied.') + ' Reloading…');
        setTimeout(() => window.location.reload(), 1500);
        return;
    }

    renderModal(state.update, null, result.message || 'Update failed.');
    refresh(true);
}

async function runBackup() {
    if (busy) return;
    busy = 'backup';
    flash = { tone: 'info', text: 'Backup running… you can keep working.' };
    render();

    const result = await api.runBackup();
    busy = null;
    flash = { tone: result.ok ? 'ok' : 'bad', text: result.message };
    await refresh(true);
    render();
}

async function checkUpdate() {
    if (busy) return;
    busy = 'check';
    flash = { tone: 'info', text: 'Checking for updates…' };
    render();

    const data = await api.checkUpdate();
    busy = null;
    flash = data?.ok
        ? { tone: data.update_available ? 'info' : 'ok', text: data.update_available ? `v${data.latest_version} is available.` : 'You are running the latest version.' }
        : { tone: 'bad', text: data?.message || 'Could not reach the update server.' };

    modalDismissedFor = null;
    if (data?.update_available) store.set(UPDATE_SNOOZE_KEY + data.latest_version, 0);
    await refresh(true);
    render();
}

root.addEventListener('click', (event) => {
    const action = event.target.closest('[data-sbw]')?.dataset.sbw;
    if (!action) return;

    switch (action) {
        case 'open':
            flash = null;
            render();
            openPanel();
            break;
        case 'close':
        case 'later':
            closePanel();
            break;
        case 'update-modal':
            modalDismissedFor = null;
            showModal();
            break;
        case 'update-later':
            snooze(UPDATE_SNOOZE_KEY + state.update.latest_version, (cfg.updateSnoozeHours || 6) * 3600000);
            modalDismissedFor = state.update.latest_version;
            hideModal();
            break;
        case 'update-run':
            runUpdate();
            break;
        case 'check-update':
            checkUpdate();
            break;
        case 'backup':
            runBackup();
            break;
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (!modal.hidden && !busy && !state?.update?.force_update) {
        modalDismissedFor = state.update.latest_version;
        hideModal();
    } else if (panelOpen) {
        closePanel();
    }
});

// Host hooks: <button data-subandl-open> anywhere, or SUBandLWidget.open().
document.addEventListener('click', (event) => {
    if (event.target.closest?.('[data-subandl-open]')) {
        event.preventDefault();
        refresh(true).then(openPanel);
    }
});

window.SUBandLWidget = { open: () => refresh(true).then(openPanel), close: closePanel, refresh: () => refresh(true) };

function onNavigate() {
    applyVisibility();
    refresh();
}

function start() {
    document.body.appendChild(root);
    document.addEventListener('inertia:navigate', onNavigate);
    window.addEventListener('popstate', onNavigate);
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && refresh());
    setInterval(() => document.visibilityState === 'visible' && refresh(true), REFRESH_MS);
    setInterval(autoPrompt, 60000);

    // Guests: stay idle until an Inertia login navigates to a signed-in page.
    if (cfg.auth) refresh(true);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
