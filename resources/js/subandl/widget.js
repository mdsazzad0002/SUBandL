/**
 * SUBandL global widget — injected into every page by InjectWidget.
 *
 *   • edge tab → offcanvas panel (1000px, max 75% wide) with two tabs:
 *       Subscription    — payment reminder (only while an amount is due),
 *                         license status, license key form
 *       Update & Backup — version / update, backup (toggle, run), history
 *   • update modal whenever a newer version is available, to apply it right
 *     there (health check → backup + install → reload), or — for a lifetime
 *     plan without the update subscription — to show what it would unlock
 *   • payment reminder modal while money is owed: every reminderMinutes once
 *     payment is late (grace period), every dueReminderHours before that;
 *     "Later" only hides the popup — a banner stays until it is paid
 *
 * The /subscription/* pages render only a #subandl-page placeholder; the
 * widget then shows the same panel full screen in "page mode" (no close button), so the
 * subscription UI exists exactly once.
 *
 * Framework-agnostic (plain DOM), so it behaves the same inside Blade, Vue or
 * React hosts. Follows Inertia page visits via the `inertia:navigate` event.
 * Host pages open the panel with `data-subandl-open` (value: "license" or
 * "update", optional) or SUBandLWidget.open(tab). Adding `data-subandl-check`
 * also starts a live update check (same as SUBandLWidget.checkUpdate()).
 */
import { createSubandl, fmt, statusTone, LICENSE_FIELDS } from './subandl.js';

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

const pageEl = document.getElementById('subandl-page');
const pageMode = !!pageEl;

let state = null; // last /subandl/widget payload, null while signed out
let fetchedAt = 0;
let busy = null; // 'update' | 'backup' | 'check' | 'license' while a flow runs
let panelOpen = false;
let tab = 'license'; // 'license' | 'update'
let status = null; // /license/status, loaded when the panel opens
let history = null; // merged update + backup history, loaded on the update tab
let licenseDraft = null;
let modalDismissedFor = null;
let updateStep = null; // null | 'check' | 'install' | 'done' while the update modal runs
let flash = null; // { tone, text }

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

const isHiddenHere = () => !pageMode && matchesHere(cfg.hiddenOn);

/* ------------------------------------------------------------------- tabs */

// Update and backup need a valid license: without one their buttons stay disabled.
const unlicensed = () => !!state?.license?.needs_redirect;
const actionOff = () => (busy || unlicensed() ? 'disabled' : '');
const unlicensedNote = () => (unlicensed() ? '<p class="sbw-note sbw-bad">Available once the license is valid.</p>' : '');

const hasLicenseTab = () => !!state && (!!state.access?.license || !!state.payment);
const hasUpdateTab = () => !!state && (!!state.update || !!state.backup);

function normalizeTab(name) {
    const wanted = name === 'update' || name === 'backup' ? 'update' : name === 'license' ? 'license' : null;
    if (wanted === 'license' && hasLicenseTab()) return 'license';
    if (wanted === 'update' && hasUpdateTab()) return 'update';
    // No preference: whatever needs attention first.
    if (state?.payment) return 'license';
    if (hasUpdateTab() && (state.update?.available || state.backup?.overdue)) return 'update';
    return hasLicenseTab() ? 'license' : 'update';
}

/* -------------------------------------------------------------------- DOM */

const icons = {
    gift: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12v10H4V12"/><path d="M2 7h20v5H2z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>',
    lock: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',
    wallet: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 7H5a2 2 0 0 1 0-4h13v4"/><path d="M3 5v14a2 2 0 0 0 2 2h15V7"/><circle cx="16" cy="14" r="1.5"/></svg>',
    check: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>',
    shield: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    close: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>',
    refresh: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 4v6h-6"/></svg>',
};

const root = document.createElement('div');
root.className = 'sbw' + (pageMode ? ' sbw-page' : '') + (cfg.edgeTab === false ? ' sbw-notab' : '');
root.hidden = true;
root.innerHTML = `
    <button type="button" class="sbw-tab" data-sbw="open" aria-label="Subscription, updates and backup">
        ${icons.shield}<span class="sbw-dot" hidden></span>
    </button>
    <div class="sbw-backdrop" data-sbw="close"></div>
    <aside class="sbw-panel" role="dialog" aria-modal="true" aria-labelledby="sbw-title" tabindex="-1">
        <header class="sbw-head">
            <div class="sbw-head-in">
                <h2 id="sbw-title">Subscription</h2>
                <nav class="sbw-tabs" role="tablist"></nav>
                ${pageMode ? '<a class="sbw-btn sbw-ghost sbw-back" href="/">&larr; Back</a>' : ''}
                <button type="button" class="sbw-icon sbw-close" data-sbw="close" aria-label="Close">${icons.close}</button>
            </div>
        </header>
        <div class="sbw-body"><div class="sbw-body-in"></div></div>
    </aside>
    <div class="sbw-modal" hidden>
        <div class="sbw-modal-card" role="alertdialog" aria-modal="true" aria-labelledby="sbw-modal-title"></div>
    </div>
    <div class="sbw-modal sbw-paymodal" hidden>
        <div class="sbw-modal-card sbw-paycard" role="alertdialog" aria-modal="true" aria-labelledby="sbw-pay-title"></div>
    </div>
    <div class="sbw-banner" role="status" hidden></div>`;

const $ = (sel) => root.querySelector(sel);
const panel = $('.sbw-panel');
const tabsNav = $('.sbw-tabs');
const body = $('.sbw-body-in');
const modal = $('.sbw-modal');
const modalCard = $('.sbw-modal-card');
const payModal = $('.sbw-paymodal');
const payCard = $('.sbw-paycard');
const banner = $('.sbw-banner');

/* ---------------------------------------------------------------- render */

function paymentInfoRows(info = {}) {
    const line = (...parts) => parts.filter(Boolean).map(esc).join(', ');
    return [
        info.company_name && `<strong>${esc(info.company_name)}</strong>`,
        line(info.contact_name, info.contact_title),
        line(info.bank_name, info.bank_branch),
        info.account_holder && esc(info.account_holder),
        info.account_no && `Account No. ${esc(info.account_no)}`,
        info.bkash_personal_number && `bKash: ${esc(info.bkash_personal_number)}`,
    ].filter(Boolean);
}

function daysUntil(value) {
    if (!value) return null;
    const d = new Date(String(value).slice(0, 10) + 'T23:59:59');
    if (isNaN(d.getTime())) return null;
    return Math.max(0, Math.ceil((d.getTime() - Date.now()) / 86400000));
}

// One sentence saying where the client stands and what happens next.
function paymentHeadline(p) {
    if (p.blocked) return 'Your subscription is paused because a payment is overdue. Pay the due amount to continue.';
    if (p.late) {
        const days = daysUntil(p.grace_ends_at);
        const when = days === null ? 'soon' : days === 0 ? 'after today' : `in ${days} day${days === 1 ? '' : 's'}`;
        return p.plan === 'lifetime'
            ? `Your update subscription payment is late. New versions stop ${when} (${date(p.grace_ends_at)}).`
            : `Your subscription payment is late. Access pauses ${when} (${date(p.grace_ends_at)}) unless it is paid.`;
    }
    return p.next_due_date ? `Your next payment is due on ${date(p.next_due_date)}.` : 'A payment is due.';
}

function renderInvoices(p) {
    if (!p.invoices?.length) return '';
    return `<div class="sbw-invoices">${p.invoices.map((inv) => `
        <div class="sbw-invoice">
            <div>
                <strong>${esc(inv.invoice_no || 'Invoice')}</strong>
                <span class="sbw-muted">${inv.period_start ? `${date(inv.period_start)} – ${date(inv.period_end)}` : esc(fmt(inv.type))}</span>
            </div>
            <div class="sbw-invoice-amt">
                <strong>${money(inv.due_amount)} ${esc(p.currency)}</strong>
                ${inv.due_date ? `<span class="sbw-muted">due ${date(inv.due_date)}</span>` : ''}
            </div>
        </div>`).join('')}</div>`;
}

function renderPayment(p) {
    const info = p.payment_info || {};
    const rows = paymentInfoRows(info);
    const tone = p.late || p.blocked ? 'sbw-danger' : 'sbw-warn';

    return `
        <section class="sbw-card ${tone}">
            <h3>${p.late || p.blocked ? 'Payment overdue' : 'Payment reminder'}</h3>
            ${(p.greeting || []).map((g) => `<p>${esc(g)}</p>`).join('')}
            <p>${esc(paymentHeadline(p))}</p>
            <div class="sbw-amount">
                <span class="sbw-label">Amount due</span>
                <strong>${money(p.due_amount)} ${esc(p.currency)}</strong>
            </div>
            ${p.monthly_fee != null && p.monthly_fee !== '' ? `<p class="sbw-kv"><span>${p.plan === 'lifetime' ? 'Monthly update subscription' : 'Monthly fee'}</span><strong>${money(p.monthly_fee)} ${esc(p.currency)}</strong></p>` : ''}
            ${p.paid_through ? `<p class="sbw-kv"><span>Paid through</span><strong>${date(p.paid_through)}</strong></p>` : ''}
            ${renderInvoices(p)}
            ${rows.length ? `<div class="sbw-box"><span class="sbw-label">How to pay</span>${rows.map((r) => `<p>${r}</p>`).join('')}</div>` : ''}
            ${info.closing ? `<p class="sbw-muted">${esc(info.closing)}</p>` : ''}
        </section>`;
}

function renderLicense() {
    if (!status) return '<section class="sbw-card"><p class="sbw-muted">Loading…</p></section>';

    const cells = LICENSE_FIELDS.map(([key, label]) => {
        const value = key === 'status'
            ? `<span class="sbw-badge sbw-${statusTone(status.status)}">${esc(fmt(status.status))}${status.in_grace_period ? ' (grace)' : ''}</span>`
            : esc(fmt(status[key]));
        return `<div><span class="sbw-label">${esc(label)}</span><div class="sbw-value">${value}</div></div>`;
    }).join('');

    return `
        <section class="sbw-card">
            <h3>License</h3>
            <div class="sbw-grid">${cells}</div>
            ${status.message ? `<p class="sbw-note sbw-bad">${esc(status.message)}</p>` : ''}
        </section>
        <section class="sbw-card">
            <h3>License key</h3>
            <form class="sbw-row" data-sbw-form="license">
                <input class="sbw-input" type="text" name="license" autocomplete="off" spellcheck="false" placeholder="XXXX-XXXX-XXXX-XXXX" value="${esc(licenseDraft ?? '')}" aria-label="License key">
                <button class="sbw-btn" type="submit" ${busy ? 'disabled' : ''}>${busy === 'license' ? 'Verifying…' : 'Save &amp; verify'}</button>
                <button class="sbw-btn sbw-ghost" type="button" data-sbw="refresh-license" ${busy ? 'disabled' : ''}>Refresh</button>
            </form>
            <p class="sbw-muted">By using this software you agree to the <a href="${esc(state.urls.terms)}">terms</a>.</p>
        </section>`;
}

function renderUpdate(u) {
    let badge;
    if (u.running || busy === 'update') badge = '<span class="sbw-badge sbw-info">Updating…</span>';
    else if (u.available && u.locked) badge = `<span class="sbw-badge sbw-warn">v${esc(u.latest_version)} · subscription needed</span>`;
    else if (u.available) badge = `<span class="sbw-badge sbw-new">v${esc(u.latest_version)} available</span>`;
    else badge = '<span class="sbw-badge sbw-ok">Up to date</span>';

    const lastResult = status?.last_update_message
        ? `<p class="sbw-muted">Last update: ${esc(status.last_update_message)}</p>` : '';

    return `
        <section class="sbw-card">
            <h3>Software version</h3>
            <p class="sbw-kv">
                <span>Installed</span>
                <span class="sbw-version-row">
                    ${!(u.available && !u.running) ? `<button type="button" class="sbw-icon sbw-version-sync" data-sbw="check-update" title="Check for update" aria-label="Check for update" ${actionOff()}>${icons.refresh}</button>` : ''}
                    <strong>v${esc(state.version)}</strong>
                </span>
            </p>
            <p class="sbw-kv"><span>Status</span>${badge}</p>
            ${u.updates_included === false ? `<p class="sbw-note sbw-bad">Your lifetime license keeps working. New versions need the monthly update subscription${u.monthly_fee ? ` (${money(u.monthly_fee)} ${esc(u.currency)}/month)` : ''}.</p>` : ''}
            ${u.retry_at
                ? `<p class="sbw-kv"><span>Provider server</span><span class="sbw-badge sbw-bad">Unreachable · retry ${date(u.retry_at, true)}</span></p>`
                : u.health ? `<p class="sbw-kv"><span>Provider server</span><span class="sbw-badge sbw-${u.health.ok ? 'ok' : 'bad'}">${u.health.ok ? 'Reachable' : 'Unreachable'} · ${ago(u.health.at)}</span></p>` : ''}
            <p class="sbw-muted">Last checked: ${u.checked_at ? ago(u.checked_at) : 'not yet'}</p>
            ${lastResult}
            ${u.available && !u.running
                ? `<div class="sbw-actions">
                    <button type="button" class="sbw-btn" data-sbw="update-modal" ${actionOff()}>${u.locked ? 'See what’s new' : `Update to v${esc(u.latest_version)}`}</button>
                </div>`
                : ''}
            ${unlicensedNote()}
        </section>`;
}

function renderBackup(b) {
    let badge;
    if (b.running || busy === 'backup') badge = '<span class="sbw-badge sbw-info">Backing up…</span>';
    else if (b.overdue) badge = '<span class="sbw-badge sbw-bad">No backup in 24 h</span>';
    else badge = '<span class="sbw-badge sbw-ok">Protected</span>';

    return `
        <section class="sbw-card">
            <h3>Cloud backup</h3>
            <p class="sbw-kv"><span>Status</span>${badge}</p>
            <p class="sbw-kv"><span>Last successful</span><strong>${b.last_success_at ? `${date(b.last_success_at, true)} <small>(${ago(b.last_success_at)})</small>` : 'never'}</strong></p>
            ${b.last_status === 'failed' && b.last_message ? `<p class="sbw-note sbw-bad">Last attempt failed: ${esc(b.last_message)}</p>` : ''}
            <label class="sbw-check">
                <input type="checkbox" data-sbw-toggle="backup" ${b.enabled ? 'checked' : ''} ${actionOff()}>
                <span>Automatic cloud backup (every ${esc(state.backup_interval_hours || 8)} hours)</span>
            </label>
            <p class="sbw-muted">${b.enabled ? '' : 'A daily backup still runs while this is off. '}Next: ${date(b.next_at, true)}</p>
            <div class="sbw-actions">
                <button type="button" class="sbw-btn sbw-ghost" data-sbw="backup" ${actionOff()}>Backup now</button>
            </div>
            ${unlicensedNote()}
        </section>`;
}

function renderHistory() {
    let rows;
    if (history === null) rows = '<tr><td colspan="4" class="sbw-muted">Loading…</td></tr>';
    else if (!history.length) rows = '<tr><td colspan="4" class="sbw-muted">No history yet.</td></tr>';
    else rows = history.map((h) => `
        <tr>
            <td>${date(h.at, true)}</td>
            <td>${esc(h.type)}</td>
            <td><span class="sbw-badge sbw-${h.ok ? 'ok' : 'bad'}">${h.ok ? 'Success' : 'Failed'}</span></td>
            <td>${esc(fmt(h.message))}</td>
        </tr>`).join('');

    return `
        <section class="sbw-card sbw-wide">
            <h3>History</h3>
            <div class="sbw-table-wrap">
                <table class="sbw-table">
                    <thead><tr><th>When</th><th>Type</th><th>Result</th><th>Details</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        </section>`;
}

// The license is unusable (invalid, blocked, unpaid): say so on top of the
// Update & Backup tab, where such users land, with the way forward.
function renderLicenseAlert() {
    if (!state.license?.needs_redirect) return '';
    const p = state.payment;
    const reason = p && (p.late || p.blocked)
        ? `The subscription is paused because ${money(p.due_amount)} ${esc(p.currency)} is overdue. Pay it to continue — access returns automatically within minutes.`
        : (status?.message ? esc(status.message) : 'This installation could not be verified, so access is paused. No data has been changed.');

    return `
        <section class="sbw-card sbw-danger sbw-wide">
            <h3>License verification required</h3>
            <p>${reason}</p>
            <div class="sbw-actions">
                ${p ? '<button type="button" class="sbw-btn sbw-ghost" data-sbw="pay-open">Payment details</button>' : ''}
                ${hasLicenseTab() ? '<button type="button" class="sbw-btn" data-sbw-tab="license">Open license</button>' : ''}
            </div>
        </section>`;
}

function renderTabs() {
    const tabs = [
        hasLicenseTab() && ['license', 'License'],
        hasUpdateTab() && ['update', 'Update & Backup'],
    ].filter(Boolean);

    tabsNav.hidden = tabs.length < 2;
    tabsNav.innerHTML = tabs.map(([key, label]) => `
        <button type="button" role="tab" class="sbw-tabbtn${tab === key ? ' sbw-active' : ''}" aria-selected="${tab === key}" data-sbw-tab="${key}">
            ${esc(label)}${key === 'update' && (state.update?.available || state.backup?.overdue) ? '<span class="sbw-pip"></span>' : ''}
        </button>`).join('');
}

function render() {
    if (!state) return;

    const flashHtml = flash ? `<p class="sbw-flash sbw-wide sbw-${flash.tone}" role="status">${esc(flash.text)}</p>` : '';
    let content;

    if (tab === 'license') {
        content = [
            state.payment ? renderPayment(state.payment) : '',
            state.access?.license ? renderLicense() : '',
        ].join('');
    } else {
        content = [
            renderLicenseAlert(),
            state.update ? renderUpdate(state.update) : '',
            state.backup ? renderBackup(state.backup) : '',
            renderHistory(),
        ].join('');
    }

    renderTabs();
    if (pageMode && state.urls.home) $('.sbw-back').href = state.urls.home;
    body.innerHTML = flashHtml + content;

    const attention = !!state.payment || !!state.update?.available || !!state.backup?.overdue;
    $('.sbw-dot').hidden = !attention;
    $('.sbw-tab').classList.toggle('sbw-attention', !!state.payment);
    renderBanner();
}

// Stays on screen while payment is late, whatever "Later" was pressed —
// only paying (or the provider clearing the due) removes it.
function renderBanner() {
    const p = state?.payment;
    const show = !!p && (p.late || p.blocked) && !pageMode && payModal.hidden;
    banner.hidden = !show;
    if (!show) return;

    banner.className = 'sbw-banner' + (p.blocked ? ' sbw-banner-bad' : '');
    banner.innerHTML = `
        <span class="sbw-banner-icon">${icons.wallet}</span>
        <span class="sbw-banner-text"><strong>${money(p.due_amount)} ${esc(p.currency)} due.</strong> ${esc(paymentHeadline(p))}</span>
        <button type="button" class="sbw-btn sbw-btn-sm" data-sbw="pay-open">Pay details</button>`;
}

function changelogHtml(u) {
    if (Array.isArray(u.changelog)) {
        return u.changelog.length ? `<ul class="sbw-news">${u.changelog.map((c) => `<li>${esc(c)}</li>`).join('')}</ul>` : '';
    }
    if (!u.changelog) return '';
    const lines = String(u.changelog).split(/\r?\n/).map((l) => l.replace(/^[-*•\s]+/, '').trim()).filter(Boolean);
    return lines.length > 1
        ? `<ul class="sbw-news">${lines.map((l) => `<li>${esc(l)}</li>`).join('')}</ul>`
        : `<p class="sbw-pre">${esc(u.changelog)}</p>`;
}

const UPDATE_STEPS = [
    ['check', 'Check server'],
    ['install', 'Back up & install'],
    ['done', 'Done'],
];

function stepperHtml() {
    const at = UPDATE_STEPS.findIndex(([key]) => key === updateStep);
    return `<ol class="sbw-steps">${UPDATE_STEPS.map(([key, label], i) => {
        const cls = i < at || updateStep === 'done' ? 'sbw-step-done' : i === at ? 'sbw-step-now' : '';
        return `<li class="${cls}"><span class="sbw-step-dot">${i < at || updateStep === 'done' ? icons.check : i + 1}</span>${esc(label)}</li>`;
    }).join('')}</ol>
    ${updateStep && updateStep !== 'done' ? '<div class="sbw-progress"><span></span></div>' : ''}`;
}

function renderModal(u, progress = null, error = null) {
    const news = changelogHtml(u);

    if (u.locked) {
        const fee = u.monthly_fee ? `${money(u.monthly_fee)} ${esc(u.currency || '')}` : null;
        modalCard.innerHTML = `
            <div class="sbw-hero sbw-hero-locked">
                <span class="sbw-hero-icon">${icons.lock}</span>
                <div>
                    <p class="sbw-hero-kicker">New version available</p>
                    <h2 id="sbw-modal-title">v${esc(u.latest_version)}${u.version_title ? ` · ${esc(u.version_title)}` : ''}</h2>
                </div>
            </div>
            <div class="sbw-modal-body">
                ${news ? `<div class="sbw-box"><span class="sbw-label">What's new</span>${news}</div>` : ''}
                <p>Your lifetime license keeps working as it is. To receive this and future versions, start the
                   <strong>monthly update subscription</strong>${fee ? ` — only <strong>${fee}</strong> per month` : ''}.</p>
                <p class="sbw-muted">Contact your provider or pay the subscription fee; the update unlocks automatically once it is paid.</p>
                <div class="sbw-actions">
                    <button type="button" class="sbw-btn sbw-ghost" data-sbw="update-later">Maybe later</button>
                    ${state.access?.license || state.payment ? '<button type="button" class="sbw-btn" data-sbw="update-subscribe">How to subscribe</button>' : ''}
                </div>
            </div>`;
        return;
    }

    const ready = !busy && updateStep === null;
    modalCard.innerHTML = `
        <div class="sbw-hero">
            <span class="sbw-hero-icon">${icons.gift}</span>
            <div>
                <p class="sbw-hero-kicker">${updateStep === 'done' ? 'Update complete' : 'A new version is ready'}</p>
                <h2 id="sbw-modal-title">v${esc(u.latest_version)}${u.version_title ? ` · ${esc(u.version_title)}` : ''}</h2>
                <p class="sbw-versions"><span>Installed v${esc(state.version)}</span> → <strong>v${esc(u.latest_version)}</strong></p>
            </div>
        </div>
        <div class="sbw-modal-body">
            ${ready && news ? `<div class="sbw-box"><span class="sbw-label">What's new</span>${news}</div>` : ''}
            ${ready ? `<ul class="sbw-assure">
                <li>${icons.check} A full backup is taken first</li>
                <li>${icons.check} Your data and settings stay as they are</li>
                <li>${icons.check} Usually done in about a minute</li>
            </ul>` : stepperHtml()}
            ${progress ? `<p class="sbw-flash sbw-info" role="status">${esc(progress)}</p>` : ''}
            ${error ? `<p class="sbw-flash sbw-bad" role="alert">${esc(error)}</p>` : ''}
            <div class="sbw-actions">
                ${u.force_update || busy ? '' : `<button type="button" class="sbw-btn sbw-ghost" data-sbw="update-later">${error ? 'Close' : 'Later'}</button>`}
                ${updateStep === 'done' ? '' : `<button type="button" class="sbw-btn sbw-btn-lg" data-sbw="update-run" ${actionOff()}>${busy === 'update' ? 'Updating…' : error ? 'Try again' : 'Update now'}</button>`}
            </div>
        </div>`;
}

function renderPayModal(p) {
    const rows = paymentInfoRows(p.payment_info || {});
    const urgent = p.late || p.blocked;
    payCard.className = 'sbw-modal-card sbw-paycard' + (urgent ? ' sbw-paycard-urgent' : '');
    payCard.innerHTML = `
        <div class="sbw-hero ${urgent ? 'sbw-hero-danger' : 'sbw-hero-warn'}">
            <span class="sbw-hero-icon">${icons.wallet}</span>
            <div>
                <p class="sbw-hero-kicker">${urgent ? 'Payment overdue' : 'Payment reminder'}</p>
                <h2 id="sbw-pay-title">${money(p.due_amount)} ${esc(p.currency)}</h2>
            </div>
        </div>
        <div class="sbw-modal-body">
            ${(p.greeting || []).map((g) => `<p>${esc(g)}</p>`).join('')}
            <p>${esc(paymentHeadline(p))}</p>
            ${renderInvoices(p)}
            ${rows.length ? `<div class="sbw-box"><span class="sbw-label">How to pay</span>${rows.map((r) => `<p>${r}</p>`).join('')}</div>` : ''}
            <div class="sbw-actions">
                <button type="button" class="sbw-btn sbw-ghost" data-sbw="pay-later">Remind me later</button>
                ${state.access?.license ? '<button type="button" class="sbw-btn" data-sbw="pay-panel">Subscription details</button>' : ''}
            </div>
        </div>`;
}

/* ------------------------------------------------------------- behaviour */

// The panel's detail data (license status, history) is only fetched while it
// is open — from this app, never from the provider.
async function loadDetails() {
    const needsHistory = tab === 'update';
    const [s, h] = await Promise.all([api.status(), needsHistory ? api.history() : null]);
    if (s.ok) {
        status = s.data;
        if (licenseDraft === null) licenseDraft = status.license_key || '';
    }
    if (needsHistory) history = h;
    render();
}

function lockScroll(lock) {
    document.documentElement.classList.toggle('sbw-noscroll', lock);
}

function openPanel(name) {
    if (!state) return;
    tab = normalizeTab(name);
    panelOpen = true;
    render();
    root.classList.add('sbw-open');
    lockScroll(true);
    panel.focus({ preventScroll: true });
    loadDetails();
}

function closePanel() {
    if (pageMode) return;
    panelOpen = false;
    flash = null;
    root.classList.remove('sbw-open');
    lockScroll(false);
}

function switchTab(name) {
    const next = normalizeTab(name);
    if (next === tab) return;
    tab = next;
    flash = null;
    if (tab === 'update') history = null;
    render();
    loadDetails();
}

function showModal() {
    renderModal(state.update);
    modal.hidden = false;
}

function hideModal() {
    modal.hidden = true;
    if (!busy) updateStep = null;
}

function showPayModal() {
    renderPayModal(state.payment);
    payModal.hidden = false;
    renderBanner();
}

// "Later": late payments come back after reminderMinutes, upcoming ones
// after dueReminderHours. The banner keeps a late payment visible meanwhile.
function snoozePayment() {
    const p = state?.payment;
    const ms = p && (p.late || p.blocked)
        ? (cfg.reminderMinutes || 10) * 60000
        : (cfg.dueReminderHours || 24) * 3600000;
    snooze(DUE_SNOOZE_KEY, ms);
    payModal.hidden = true;
    renderBanner();
}

// Decides what pops up on its own after every state refresh / timer tick.
function autoPrompt() {
    if (!state || root.hidden || busy) return;

    if (state.payment && !panelOpen && payModal.hidden && modal.hidden && !snoozed(DUE_SNOOZE_KEY)) {
        showPayModal();
        return;
    }

    const u = state.update;
    if (u?.available && !u.running && !unlicensed() && modal.hidden && payModal.hidden
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

    // Don't wipe what the user is typing into the license form.
    if (!(root.contains(document.activeElement) && document.activeElement.tagName === 'INPUT')) {
        render();
    }
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
    const hasContent = hasLicenseTab() || hasUpdateTab();
    root.hidden = !hasContent || isHiddenHere();
    if (root.hidden) {
        root.classList.remove('sbw-open');
        panelOpen = false;
        lockScroll(false);
        hideModal();
        payModal.hidden = true;
    }
    renderBanner();
}

async function runUpdate() {
    if (busy || unlicensed()) return;
    busy = 'update';
    updateStep = 'check';
    renderModal(state.update, 'Checking the update server…');
    render();

    // 1. The server confirms the provider is reachable before anything changes.
    const pre = await api.preflight();
    if (!pre.ok) {
        busy = null;
        updateStep = null;
        renderModal(state.update, null, pre.message || 'The update server is not reachable. Please try again later.');
        refresh(true);
        return;
    }

    // 2. Backup + download + install, one version step at a time.
    updateStep = 'install';
    renderModal(state.update, 'Backing up and installing… please keep this page open.');
    const result = await api.runUpdate(({ message }) => renderModal(state.update, message));
    busy = null;

    if (result.ok) {
        updateStep = 'done';
        renderModal(state.update, (result.message || 'Update applied.') + ' Reloading…');
        setTimeout(() => window.location.reload(), 1800);
        return;
    }

    updateStep = null;
    renderModal(state.update, null, result.message || 'Update failed.');
    refresh(true);
}

async function runBackup() {
    if (busy || unlicensed()) return;
    busy = 'backup';
    flash = { tone: 'info', text: 'Backup running… you can keep working.' };
    render();

    const result = await api.runBackup();
    busy = null;
    flash = { tone: result.ok ? 'ok' : 'bad', text: result.message };
    await refresh(true);
    if (panelOpen) loadDetails();
}

async function toggleBackup(enabled) {
    if (busy || unlicensed()) return;
    const r = await api.toggleBackup(enabled);
    flash = r.ok
        ? { tone: 'ok', text: `Automatic backup ${r.enabled ? 'enabled' : 'disabled'}.` }
        : { tone: 'bad', text: 'Could not change the setting.' };
    await refresh(true);
    render();
}

async function checkUpdate() {
    if (busy) return;
    if (unlicensed()) {
        flash = { tone: 'bad', text: 'Updates are available once the license is valid.' };
        render();
        return;
    }
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

async function saveLicense(value) {
    if (busy) return;
    busy = 'license';
    flash = { tone: 'info', text: 'Verifying the license…' };
    render();

    const r = await api.saveLicense(value);
    busy = null;
    flash = { tone: r.ok ? 'ok' : 'bad', text: r.message };
    await refresh(true);
    await loadDetails();
}

async function refreshLicense() {
    if (busy) return;
    busy = 'license';
    flash = { tone: 'info', text: 'Checking the license…' };
    render();

    const r = await api.refreshLicense();
    busy = null;
    if (r.ok) {
        status = r.data;
        flash = { tone: 'ok', text: 'License refreshed.' };
    } else {
        flash = { tone: 'bad', text: r.data?.message || 'Could not refresh the license.' };
    }
    await refresh(true);
    render();
}

root.addEventListener('click', (event) => {
    const tabBtn = event.target.closest('[data-sbw-tab]');
    if (tabBtn) {
        switchTab(tabBtn.dataset.sbwTab);
        return;
    }

    const action = event.target.closest('[data-sbw]')?.dataset.sbw;
    if (!action) return;

    switch (action) {
        case 'open':
            flash = null;
            openPanel();
            break;
        case 'close':
        case 'later':
            closePanel();
            break;
        case 'update-modal':
            if (unlicensed()) break;
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
        case 'update-subscribe':
            modalDismissedFor = state.update.latest_version;
            hideModal();
            openPanel('license');
            break;
        case 'pay-open':
            showPayModal();
            break;
        case 'pay-later':
            snoozePayment();
            break;
        case 'pay-panel':
            payModal.hidden = true;
            snooze(DUE_SNOOZE_KEY, (cfg.reminderMinutes || 10) * 60000);
            openPanel('license');
            break;
        case 'check-update':
            checkUpdate();
            break;
        case 'backup':
            runBackup();
            break;
        case 'refresh-license':
            refreshLicense();
            break;
    }
});

root.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-sbw-form="license"]');
    if (!form) return;
    event.preventDefault();
    const value = form.elements.license.value.trim();
    if (value) saveLicense(value);
});

root.addEventListener('input', (event) => {
    if (event.target.name === 'license') licenseDraft = event.target.value;
});

root.addEventListener('change', (event) => {
    if (event.target.dataset?.sbwToggle === 'backup') toggleBackup(event.target.checked);
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (!payModal.hidden) {
        snoozePayment();
    } else if (!modal.hidden && !busy && !state?.update?.force_update) {
        modalDismissedFor = state.update.latest_version;
        hideModal();
    } else if (panelOpen) {
        closePanel();
    }
});

// Host hooks: <a href="/subscription/license" data-subandl-open="license"> anywhere
// (the link still works if the widget is not loaded), or SUBandLWidget.open(tab).
document.addEventListener('click', (event) => {
    const trigger = event.target.closest?.('[data-subandl-open]');
    if (!trigger || !state || pageMode) return;
    event.preventDefault();
    flash = null;
    openPanel(trigger.dataset.subandlOpen || undefined);
    // data-subandl-check: the trigger is a "Check for update" button — run
    // the live check at once instead of waiting for a second click.
    if (trigger.hasAttribute('data-subandl-check')) {
        checkUpdate();
    } else {
        refresh(true);
    }
});

window.SUBandLWidget = {
    open: (name) => refresh(true).then(() => openPanel(name)),
    close: closePanel,
    refresh: () => refresh(true),
    checkUpdate: () => refresh(true).then(() => {
        openPanel('update');
        return checkUpdate();
    }),
};

function onNavigate() {
    applyVisibility();
    refresh();
}

async function start() {
    document.body.appendChild(root);
    document.addEventListener('inertia:navigate', onNavigate);
    window.addEventListener('popstate', onNavigate);
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && refresh());
    setInterval(() => document.visibilityState === 'visible' && refresh(true), REFRESH_MS);
    setInterval(autoPrompt, 60000);

    // Guests: stay idle until an Inertia login navigates to a signed-in page.
    if (!cfg.auth) return;

    await refresh(true);
    if (pageMode && state) openPanel(pageEl.dataset.tab);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
