/**
 * SUBandL global widget — injected into every page by InjectWidget.
 *
 *   • edge tab → offcanvas panel (1000px, max 75% wide) with two tabs:
 *       Subscription    — payment reminder (only while an amount is due),
 *                         license status, license key form
 *       Update & Backup — version / update, backup (toggle, run), history
 *   • update modal whenever a newer version is available, to apply it right
 *     there (check → backup → download → merge → migrate, each with its own
 *     progress bar and percentage → reload), or — for a lifetime
 *     plan without the update subscription — to show what it would unlock
 *   • provider notices (sent with every license / update check): a popup
 *     once per browser for notices marked "popup", and a Notices tab
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
import { createSubandl, fmt, statusTone, updateStages, LICENSE_FIELDS } from './subandl.js';

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
const NOTICE_READ_KEY = 'subandl:notices-read';

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
let updateStep = null; // null | 'running' | 'failed' | 'done' once the update modal starts
let liveProgress = null; // the server's per-stage snapshot of the version step in flight
let liveStepNo = 1; // which version step (multi-version upgrades run one per request)
let flash = null; // { tone, text }
let paying = null; // pay_url of the invoice being paid in the popup window
let noticeShown = null; // key of the notice in the popup

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

// Notice keys (id:revision) this browser has seen. An edited notice gets a new
// revision, so it counts as unread again.
function readNotices() {
    try {
        const list = JSON.parse(store.get(NOTICE_READ_KEY) || '[]');
        return Array.isArray(list) ? list : [];
    } catch (e) {
        return [];
    }
}

function markNoticesRead(keys) {
    const read = readNotices();
    const fresh = keys.filter((k) => !read.includes(k));
    if (!fresh.length) return;
    store.set(NOTICE_READ_KEY, JSON.stringify([...read, ...fresh].slice(-200)));
    // Host UIs that list the same notices (a topbar bell) update their count.
    window.dispatchEvent(new CustomEvent('subandl:notices-read', { detail: { keys: fresh } }));
}

const notices = () => (Array.isArray(state?.notices) ? state.notices : []);
const unreadNotices = () => {
    const read = readNotices();
    return notices().filter((n) => !read.includes(n.key));
};

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

const hasLicenseTab = () => !!state && (!!state.access?.license || !!state.payment || !!state.account?.wallet);
const hasUpdateTab = () => !!state && (!!state.update || !!state.backup);
const hasNoticeTab = () => notices().length > 0;

function normalizeTab(name) {
    const wanted = name === 'update' || name === 'backup' ? 'update' : name === 'license' || name === 'notices' ? name : null;
    if (wanted === 'license' && hasLicenseTab()) return 'license';
    if (wanted === 'update' && hasUpdateTab()) return 'update';
    if (wanted === 'notices' && hasNoticeTab()) return 'notices';
    // No preference: whatever needs attention first.
    if (state?.payment && hasLicenseTab()) return 'license';
    if (unreadNotices().length) return 'notices';
    if (hasUpdateTab() && (state.update?.available || state.backup?.overdue)) return 'update';
    return hasLicenseTab() ? 'license' : hasUpdateTab() ? 'update' : 'notices';
}

/* -------------------------------------------------------------------- DOM */

const icons = {
    gift: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12v10H4V12"/><path d="M2 7h20v5H2z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>',
    lock: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',
    wallet: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 7H5a2 2 0 0 1 0-4h13v4"/><path d="M3 5v14a2 2 0 0 0 2 2h15V7"/><circle cx="16" cy="14" r="1.5"/></svg>',
    check: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>',
    shield: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    close: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>',
    megaphone: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.5 5.5a9 9 0 0 1 0 13"/></svg>',
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
    <div class="sbw-modal sbw-noticemodal" hidden>
        <div class="sbw-modal-card sbw-noticecard" role="alertdialog" aria-modal="true" aria-labelledby="sbw-notice-title"></div>
    </div>
    <div class="sbw-modal sbw-paymodal" hidden>
        <div class="sbw-modal-card sbw-paycard" role="alertdialog" aria-modal="true" aria-labelledby="sbw-pay-title"></div>
    </div>
    <div class="sbw-banner" role="status" hidden></div>
    <div class="sbw-mini" role="status" aria-live="polite" hidden>
        <button type="button" class="sbw-mini-main" data-sbw="update-restore" title="Show details">
            <span class="sbw-mini-ring"><span class="sbw-mini-pct">0%</span></span>
            <span class="sbw-mini-text"><strong class="sbw-mini-title">Updating…</strong><span class="sbw-mini-sub">Click to see details</span></span>
        </button>
        <button type="button" class="sbw-btn sbw-mini-act" data-sbw="update-run-mini" hidden>Install now</button>
        <button type="button" class="sbw-icon sbw-mini-x" data-sbw="update-mini-close" aria-label="Hide" hidden>${icons.close}</button>
        <span class="sbw-mini-bar"><span></span></span>
    </div>`;

const $ = (sel) => root.querySelector(sel);
const panel = $('.sbw-panel');
const tabsNav = $('.sbw-tabs');
const body = $('.sbw-body-in');
const modal = $('.sbw-modal');
const modalCard = $('.sbw-modal-card');
const payModal = $('.sbw-paymodal');
const payCard = $('.sbw-paycard');
const banner = $('.sbw-banner');
const noticeModal = $('.sbw-noticemodal');
const noticeCard = $('.sbw-noticecard');
const mini = $('.sbw-mini');

// Minimized update: the modal is hidden and a small pill (bottom corner) keeps
// showing the progress while the user works elsewhere in the app.
let minimized = false;
let pillDismissed = false; // the user closed the download pill: don't bring it back on this page

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

// "12 days left" / "Ends today" / "Ended 3 days ago" for a paid-through date.
function daysLeft(value) {
    if (!value) return null;
    const end = new Date(String(value).slice(0, 10) + 'T00:00:00');
    if (isNaN(end.getTime())) return null;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const days = Math.round((end.getTime() - today.getTime()) / 86400000);
    if (days > 1) return { days, text: `${days} days left`, tone: days <= 5 ? 'warn' : 'ok' };
    if (days === 1) return { days, text: '1 day left', tone: 'warn' };
    if (days === 0) return { days, text: 'Ends today', tone: 'warn' };
    return { days, text: `Ended ${-days} day${days === -1 ? '' : 's'} ago`, tone: 'bad' };
}

// Wallet balance, due, paid-through and "Add funds" — shown even with nothing due.
function renderAccount(a) {
    const w = a.wallet;
    const cur = esc(w?.currency || a.currency || 'BDT');
    const left = daysLeft(a.paid_through);
    const due = Number(a.due_amount) || 0;
    const suggested = amountNeeded(due);
    const chips = [...new Set([suggested || null, 500, 1000, 2000, 5000].filter(Boolean))];

    return `
        <section class="sbw-card sbw-account">
            <div class="sbw-account-hero ${a.late ? 'sbw-account-late' : ''}">
                <div class="sbw-account-stat">
                    <span>Wallet balance</span>
                    <strong>${w ? `${money(w.balance)} <small>${cur}</small>` : '—'}</strong>
                </div>
                <div class="sbw-account-stat">
                    <span>Total due</span>
                    <strong>${money(due)} <small>${cur}</small></strong>
                </div>
                <div class="sbw-account-stat">
                    <span>${a.plan === 'lifetime' ? 'Updates until' : 'Paid through'}</span>
                    <strong>${a.paid_through ? date(a.paid_through) : (a.plan === 'lifetime' ? 'Lifetime' : '—')}</strong>
                    ${left ? `<em class="sbw-days sbw-days-${left.tone}">${esc(left.text)}</em>` : ''}
                </div>
            </div>
            ${w?.deposit_url ? `
            <form class="sbw-account-pay" data-sbw-form="deposit">
                <label class="sbw-label" for="sbw-deposit-amount">${due > 0 ? 'Pay the due or add funds' : 'Add funds to your wallet'}</label>
                <div class="sbw-account-row">
                    <input id="sbw-deposit-amount" class="sbw-input" type="number" name="amount" min="10" step="0.01" inputmode="decimal" placeholder="Amount (${cur})" value="${esc(suggested)}">
                    <button class="sbw-btn" type="submit" ${paying ? 'disabled' : ''}>${paying === w.deposit_url ? 'Paying…' : due > 0 ? 'Pay now' : 'Add funds'}</button>
                </div>
                <div class="sbw-chips">${chips.map((c) => `<button type="button" class="sbw-chip" data-sbw="deposit-chip" data-amount="${esc(c)}">${c === suggested ? `Needed ${money(c)}` : money(c)}</button>`).join('')}</div>
                <p class="sbw-muted">Paid securely on the provider's site in a small window. Any due invoices are paid from the wallet automatically.</p>
            </form>` : ''}
        </section>`;
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
    // Advance billing: pay by the paid-through date to keep going.
    const by = p.paid_through || p.next_due_date;
    const left = daysLeft(by);
    return by ? `Your subscription is paid through ${date(by)}${left && left.days >= 0 ? ` (${left.text})` : ''}. Pay in advance to continue after that.` : 'A payment is due.';
}

// Each unpaid invoice with what it is for: `reason` comes from the provider
// (its line items, e.g. "Installation + Monthly Subscription Fee"); older
// providers send none, so the invoice type stands in.
function renderInvoices(p) {
    if (!p.invoices?.length) return '';
    return `<div class="sbw-invoices">${p.invoices.map((inv) => `
        <div class="sbw-invoice">
            <div>
                <strong>${esc(inv.invoice_no || 'Invoice')}</strong>
                <span class="sbw-invoice-reason">${esc(inv.reason || fmt(inv.type))}</span>
                ${inv.period_start ? `<span class="sbw-muted">${date(inv.period_start)} – ${date(inv.period_end)}</span>` : ''}
            </div>
            <div class="sbw-invoice-amt">
                <strong>${money(inv.due_amount)} ${esc(p.currency)}</strong>
                ${inv.due_date ? `<span class="sbw-muted">due ${date(inv.due_date)}</span>` : ''}
                ${inv.pay_url ? `<button type="button" class="sbw-btn sbw-btn-sm sbw-pay-now" data-sbw="pay-online" data-url="${esc(inv.pay_url)}" ${paying ? 'disabled' : ''}>${paying === inv.pay_url ? 'Paying…' : 'Pay now'}</button>` : ''}
            </div>
        </div>`).join('')}</div>`;
}

// The total's parts — subscription fee and other dues — each only when owed.
function renderDueBreakdown(p) {
    const row = (label, value) => Number(value) > 0
        ? `<p class="sbw-kv"><span>${label}</span><strong>${money(value)} ${esc(p.currency)}</strong></p>`
        : '';
    // A single part equal to the total adds nothing.
    if (!(Number(p.other_due) > 0)) return '';
    return row('Subscription fee', p.fee_due) + row('Other dues', p.other_due);
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
                <span class="sbw-label">Total due</span>
                <strong>${money(p.due_amount)} ${esc(p.currency)}</strong>
            </div>
            ${renderDueBreakdown(p)}
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
        const left = key === 'paid_through' ? daysLeft(status[key]) : null;
        const value = key === 'status'
            ? `<span class="sbw-badge sbw-${statusTone(status.status)}">${esc(fmt(status.status))}${status.in_grace_period ? ' (grace)' : ''}</span>`
            : esc(fmt(status[key])) + (left ? ` <em class="sbw-days sbw-days-${left.tone}">${esc(left.text)}</em>` : '');
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
            ${status.device_id ? `
            <div class="sbw-box">
                <p class="sbw-kv"><span>Running on</span><span class="sbw-badge sbw-${status.environment === 'live' ? 'ok' : 'info'}">${status.environment === 'live' ? 'Live domain' : 'Local (localhost / IP)'}</span></p>
                <p class="sbw-kv"><span>Licensed domain</span><span class="sbw-code">${esc(status.domain || 'Not bound yet')}</span></p>
                <p class="sbw-kv"><span>Device serial</span><span class="sbw-code">${esc(status.device_serial || 'Unavailable on this host')}</span></p>
                <p class="sbw-kv"><span>Installation ID</span><span class="sbw-code">${esc(status.device_id)}</span></p>
            </div>` : ''}
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
        hasNoticeTab() && ['notices', 'Notices'],
    ].filter(Boolean);

    const unread = unreadNotices().length;
    tabsNav.hidden = tabs.length < 2;
    tabsNav.innerHTML = tabs.map(([key, label]) => `
        <button type="button" role="tab" class="sbw-tabbtn${tab === key ? ' sbw-active' : ''}" aria-selected="${tab === key}" data-sbw-tab="${key}">
            ${esc(label)}${key === 'update' && (state.update?.available || state.backup?.overdue) ? '<span class="sbw-pip"></span>' : ''}${key === 'notices' && unread ? `<span class="sbw-count">${unread}</span>` : ''}
        </button>`).join('');
}

function render() {
    if (!state) return;

    const flashHtml = flash ? `<p class="sbw-flash sbw-wide sbw-${flash.tone}" role="status">${esc(flash.text)}</p>` : '';
    let content;

    if (tab === 'notices') {
        content = renderNotices();
    } else if (tab === 'license') {
        content = [
            state.account ? renderAccount(state.account) : '',
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
    swapHtml(body, flashHtml + content);

    // What the Notices tab showed now counts as read (it was rendered "New" once).
    if (tab === 'notices' && panelOpen) markNoticesRead(notices().map((n) => n.key));

    const attention = !!state.payment || !!state.update?.available || !!state.backup?.overdue || unreadNotices().length > 0;
    $('.sbw-dot').hidden = !attention;
    $('.sbw-tab').classList.toggle('sbw-attention', !!state.payment);
    renderBanner();
}

const NOTICE_HERO = { info: '', success: 'sbw-hero-ok', warning: 'sbw-hero-warn', danger: 'sbw-hero-danger' };
const NOTICE_KICKER = { info: 'Notice', success: 'Good news', warning: 'Important', danger: 'Urgent' };

// Plain text from the provider: escaped, line breaks kept.
const noticeBody = (n) => (n.body ? `<p class="sbw-notice-body">${esc(n.body).replace(/\r?\n/g, '<br>')}</p>` : '');
const noticeLink = (n, cls = 'sbw-btn') => (n.link_url
    ? `<a class="${cls}" href="${esc(n.link_url)}" target="_blank" rel="noopener noreferrer">${esc(n.link_label || 'Open link')}</a>`
    : '');

function renderNotices() {
    const list = notices();
    if (!list.length) return '<section class="sbw-card sbw-wide"><p class="sbw-muted">No notices right now.</p></section>';
    const read = readNotices();

    return list.map((n) => `
        <section class="sbw-card sbw-wide sbw-notice sbw-notice-${esc(n.type)}">
            <div class="sbw-notice-head">
                <h3>${esc(n.title)}</h3>
                ${read.includes(n.key) ? '' : '<span class="sbw-badge sbw-new">New</span>'}
            </div>
            ${n.published_at ? `<p class="sbw-muted">${date(n.published_at, true)}${n.ends_at ? ` · until ${date(n.ends_at, true)}` : ''}</p>` : ''}
            ${noticeBody(n)}
            ${n.link_url ? `<div class="sbw-actions">${noticeLink(n, 'sbw-btn sbw-btn-sm')}</div>` : ''}
        </section>`).join('');
}

function showNoticeModal(n) {
    noticeShown = n.key;
    noticeCard.innerHTML = `
        <div class="sbw-hero ${NOTICE_HERO[n.type] || ''}">
            <span class="sbw-hero-icon">${icons.megaphone}</span>
            <div>
                <p class="sbw-hero-kicker">${esc(NOTICE_KICKER[n.type] || 'Notice')}</p>
                <h2 id="sbw-notice-title">${esc(n.title)}</h2>
            </div>
        </div>
        <div class="sbw-modal-body">
            ${noticeBody(n)}
            <div class="sbw-actions">
                ${notices().length > 1 ? '<button type="button" class="sbw-btn sbw-ghost" data-sbw="notice-all">All notices</button>' : ''}
                ${noticeLink(n, 'sbw-btn sbw-ghost')}
                <button type="button" class="sbw-btn sbw-btn-lg" data-sbw="notice-ok">Got it</button>
            </div>
        </div>`;
    noticeModal.hidden = false;
}

// Closing the popup marks that notice read, then the next one (if any) follows.
function hideNoticeModal() {
    if (noticeShown) markNoticesRead([noticeShown]);
    noticeShown = null;
    noticeModal.hidden = true;
    if (state) render();
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

const STAGE_STATUS = { done: 'Done', skipped: 'Skipped', failed: 'Failed' };

// Share of the overall bar per stage, roughly how long each one takes, so the
// overall bar moves evenly instead of racing through the quick ones.
const STAGE_WEIGHT = { check: 5, backup: 15, download: 25, merge: 35, migrate: 20 };

// One row per stage — check, backup, download, merge, migrate — each with its
// own bar and percentage, from the server's live snapshot.
function stagesView() {
    const stages = updateStages(liveProgress);
    if (!liveProgress && updateStep === 'running') {
        Object.assign(stages[0], { status: 'running', percent: 5, detail: 'Checking the update server…' });
    }

    const weight = (st) => STAGE_WEIGHT[st.key] || 0;
    const totalWeight = stages.reduce((sum, st) => sum + weight(st), 0) || 1;
    const overall = Math.floor(stages.reduce((sum, st) => sum + weight(st) * (st.status === 'done' || st.status === 'skipped' ? 100 : st.percent), 0) / totalWeight);
    const target = liveProgress?.to_version;

    return {
        title: (target ? `Installing v${target}` : 'Preparing the update') + (liveStepNo > 1 ? ` · step ${liveStepNo}` : ''),
        overall: updateStep === 'done' ? 100 : overall,
        stages: stages.map((st, i) => {
            const pct = st.status === 'skipped' ? 100 : st.percent;
            const mark = st.status === 'done' || st.status === 'skipped' ? 'check' : st.status === 'failed' ? '!' : String(i + 1);
            return {
                ...st,
                pct,
                mark,
                // Running with nothing measurable yet (backup, a download of unknown size).
                busy: st.status === 'running' && st.percent < 1,
                pctLabel: STAGE_STATUS[st.status] && st.status !== 'done' ? STAGE_STATUS[st.status] : pct + '%',
            };
        }),
    };
}

const markHtml = (mark) => (mark === 'check' ? icons.check : esc(mark));

function stagesHtml() {
    const view = stagesView();
    return `<p class="sbw-stages-head"><span>${esc(view.title)}</span><strong>${view.overall}%</strong></p>
        <div class="sbw-bar sbw-bar-overall${updateStep === 'failed' ? ' sbw-bar-failed' : ''}" role="progressbar" aria-label="Overall progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${view.overall}"><span style="width:${view.overall}%"></span></div>
        <ol class="sbw-stages">${view.stages.map((st) => `<li class="sbw-stage sbw-stage-${esc(st.status)}" data-stage="${esc(st.key)}">
                <div class="sbw-stage-head">
                    <span class="sbw-stage-dot" data-mark="${esc(st.mark)}">${markHtml(st.mark)}</span>
                    <span class="sbw-stage-label">${esc(st.label)}</span>
                    <span class="sbw-stage-pct">${esc(st.pctLabel)}</span>
                </div>
                <div class="sbw-bar${st.busy ? ' sbw-bar-busy' : ''}" role="progressbar" aria-label="${esc(st.label)}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${st.pct}"><span style="width:${st.pct}%"></span></div>
                <p class="sbw-stage-detail">${esc(st.detail || '')}</p>
            </li>`).join('')}</ol>`;
}

// A progress tick only moves the numbers: rebuilding the card on every poll
// restarted the bar animations, jumped the layout and reset its scroll —
// the modal "blinked" for the whole update.
function patchStages() {
    const view = stagesView();
    const setText = (el, text) => {
        if (el && el.textContent !== text) el.textContent = text;
    };

    const setBar = (bar, pct, busy) => {
        if (!bar) return;
        bar.classList.toggle('sbw-bar-busy', !!busy);
        if (bar.getAttribute('aria-valuenow') !== String(pct)) {
            bar.setAttribute('aria-valuenow', pct);
            bar.firstElementChild.style.width = pct + '%';
        }
    };

    updateMini(view);
    const head = modalCard.querySelector('.sbw-stages-head');
    setText(head?.querySelector('span'), view.title);
    setText(head?.querySelector('strong'), view.overall + '%');
    setBar(modalCard.querySelector('.sbw-bar-overall'), view.overall);

    for (const st of view.stages) {
        const li = modalCard.querySelector(`[data-stage="${st.key}"]`);
        if (!li) continue;

        const cls = 'sbw-stage sbw-stage-' + st.status;
        if (li.className !== cls) li.className = cls;

        const dot = li.querySelector('.sbw-stage-dot');
        if (dot && dot.dataset.mark !== st.mark) {
            dot.dataset.mark = st.mark;
            dot.innerHTML = markHtml(st.mark);
        }

        setText(li.querySelector('.sbw-stage-pct'), st.pctLabel);
        setText(li.querySelector('.sbw-stage-detail'), st.detail || '');

        setBar(li.querySelector('.sbw-bar'), st.pct, st.busy);
    }
}

let modalShape = null; // what the update card was last built for, minus the numbers

// Rebuilding a container with innerHTML drops keyboard focus to <body> and resets
// its scroll, so the modal looked like it "refreshed" and stole focus on every
// rebuild. Swap the markup, then put focus and scroll back where they were.
function swapHtml(el, html) {
    if (el.innerHTML === html) return;
    const active = document.activeElement;
    const focusKey = el.contains(active) ? active.dataset?.sbw || active.name || active.id || null : null;
    const scrollers = [el, el.parentElement].filter(Boolean).map((node) => [node, node.scrollTop]);
    el.innerHTML = html;
    scrollers.forEach(([node, top]) => (node.scrollTop = top));
    if (focusKey) {
        const again = el.querySelector(`[data-sbw="${CSS.escape(focusKey)}"], [name="${CSS.escape(focusKey)}"], #${CSS.escape(focusKey)}`);
        if (again && !again.disabled) again.focus({ preventScroll: true });
    }
}

const STAGE_RANK = { pending: 0, running: 1, done: 2, skipped: 2, failed: 2 };
const STAGE_ORDER = ['check', 'backup', 'download', 'merge', 'migrate'];

// Polls and chunk responses race on a slow line: an older snapshot can land after a
// newer one and drag the bars backwards. Within one step only ever move forward.
function mergeProgress(prev, next) {
    if (!next) return prev;
    if (!prev || !prev.stages || (prev.run && next.run && prev.run !== next.run)) return next;
    const stages = { ...(next.stages || {}) };
    for (const [key, old] of Object.entries(prev.stages)) {
        const cur = stages[key];
        if (!cur) {
            stages[key] = old;
            continue;
        }
        const oldRank = STAGE_RANK[old.status] ?? 0;
        const curRank = STAGE_RANK[cur.status] ?? 0;
        if (curRank < oldRank) stages[key] = old;
        else if (curRank === oldRank && (cur.percent || 0) < (old.percent || 0)) stages[key] = { ...cur, percent: old.percent };
    }
    const order = (key) => STAGE_ORDER.indexOf(key);
    const current = order(next.current) >= order(prev.current) ? next.current : prev.current;
    return { ...next, current, stages };
}

// The status lines under the bars (info / error / background download) change far
// more often than the card's layout: update their text in place.
function patchModalLines(progress, error, pkg) {
    const set = (sel, text) => {
        const el = modalCard.querySelector(sel);
        if (!el) return;
        if (el.textContent !== (text || '')) el.textContent = text || '';
        el.hidden = !text;
    };
    set('[data-sbw-line="info"]', progress);
    set('[data-sbw-line="error"]', error);
    const pkgEl = modalCard.querySelector('[data-sbw-line="pkg"]');
    if (pkgEl) {
        const html = packageHtml(pkg || {});
        if (pkgEl.innerHTML !== html) pkgEl.innerHTML = html;
    }
}

// The package is fetched in the background as soon as a new version is found,
// so the click only has to confirm and install it.
// How many versions this update goes through (several: installed one after another).
const updateCount = (u) => Math.max(1, (u?.pending_versions || []).length, u?.package?.count || 0);
const targetVersion = (u) => u?.target_version || u?.latest_version;

function packageHtml(pkg) {
    const mb = (n) => (n / 1048576).toFixed(1) + ' MB';
    const many = (pkg.count || 1) > 1;
    if (pkg.state === 'ready') {
        return `<p class="sbw-flash sbw-ok" role="status">${icons.check} ${many ? `All ${pkg.count} updates downloaded` : 'Already downloaded'}${pkg.total ? ` (${mb(pkg.total)})` : ''} — installs in moments.</p>`;
    }
    if (pkg.state === 'downloading') {
        const pct = pkg.total ? Math.floor((100 * pkg.received) / pkg.total) : null;
        const which = many ? ` ${Math.min(pkg.count, (pkg.ready_count || 0) + 1)} of ${pkg.count}${pkg.current ? ` (v${esc(pkg.current)})` : ''}` : '';
        return `<p class="sbw-flash sbw-info" role="status">Downloading${which} in the background…${pct !== null ? ` ${pct}% of ${mb(pkg.total)}` : ''} You'll get a notice when ${many ? 'all are' : 'it is'} ready; you can also install now.</p>`;
    }
    return '';
}

function renderModal(u, progress = null, error = null) {
    const news = changelogHtml(u);

    if (u.locked) {
        modalShape = null;
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

    // Same card, new numbers: patch in place instead of rebuilding.
    const pkg = u.package || {};
    const shape = JSON.stringify([u.latest_version, targetVersion(u), updateCount(u), state.version, updateStep, busy, !!error, ready, pkg.state, !!(ready && news)]);
    if (shape === modalShape && modalCard.firstElementChild) {
        if (modalCard.querySelector('.sbw-stages')) patchStages();
        patchModalLines(progress, error, pkg);
        return;
    }
    modalShape = shape;

    swapHtml(modalCard, `
        <div class="sbw-hero">
            <span class="sbw-hero-icon">${icons.gift}</span>
            <div>
                <p class="sbw-hero-kicker">${updateStep === 'done' ? 'Update complete' : updateCount(u) > 1 ? `${updateCount(u)} updates are ready` : 'A new version is ready'}</p>
                <h2 id="sbw-modal-title">${updateCount(u) > 1 ? `v${esc(u.latest_version)} → v${esc(targetVersion(u))}` : `v${esc(u.latest_version)}${u.version_title ? ` · ${esc(u.version_title)}` : ''}`}</h2>
                <p class="sbw-versions"><span>Installed v${esc(state.version)}</span> → <strong>v${esc(targetVersion(u))}</strong>${updateCount(u) > 1 ? ` <span>(${updateCount(u)} steps, one after another)</span>` : ''}</p>
            </div>
        </div>
        <div class="sbw-modal-body">
            ${ready && news ? `<div class="sbw-box"><span class="sbw-label">What's new</span>${news}</div>` : ''}
            ${ready ? `<div data-sbw-line="pkg">${packageHtml(pkg)}</div>` : ''}
            ${ready ? `<ul class="sbw-assure">
                <li>${icons.check} The database is backed up first — in the background</li>
                <li>${icons.check} Your data and settings stay as they are</li>
                <li>${icons.check} Starts right away — usually done in about a minute</li>
            </ul>` : stagesHtml()}
            <p class="sbw-flash sbw-info" role="status" data-sbw-line="info"${progress ? '' : ' hidden'}>${esc(progress || '')}</p>
            <p class="sbw-flash sbw-bad" role="alert" data-sbw-line="error"${error ? '' : ' hidden'}>${esc(error || '')}</p>
            <div class="sbw-actions">
                ${u.force_update || busy ? '' : `<button type="button" class="sbw-btn sbw-ghost" data-sbw="update-later">${error ? 'Close' : 'Later'}</button>`}
                ${busy === 'update' || (ready && pkg.state === 'downloading') ? `<button type="button" class="sbw-btn sbw-ghost" data-sbw="update-minimize" title="${busy ? 'Keep working — the update continues in the background' : 'Keep working — you get a notice when the download is done'}">Minimize</button>` : ''}
                ${updateStep === 'done' ? '' : `<button type="button" class="sbw-btn sbw-btn-lg" data-sbw="update-run" ${actionOff()}>${busy === 'update' ? 'Updating…' : error ? 'Try again' : pkg.state === 'ready' ? 'Install now' : 'Update now'}</button>`}
            </div>
        </div>`);
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
            ${flash ? `<p class="sbw-flash sbw-${flash.tone}" role="status">${esc(flash.text)}</p>` : ''}
            ${(p.greeting || []).map((g) => `<p>${esc(g)}</p>`).join('')}
            <p>${esc(paymentHeadline(p))}</p>
            ${renderDueBreakdown(p)}
            ${renderInvoices(p)}
            ${renderPayDueForm(p)}
            ${rows.length ? `<div class="sbw-box"><span class="sbw-label">How to pay</span>${rows.map((r) => `<p>${r}</p>`).join('')}</div>` : ''}
            <div class="sbw-actions">
                <button type="button" class="sbw-btn sbw-ghost" data-sbw="pay-later">Remind me later</button>
                ${state.access?.license ? '<button type="button" class="sbw-btn sbw-ghost" data-sbw="pay-panel">Subscription details</button>' : ''}
                ${!state?.account?.wallet?.deposit_url && payDueTarget(p) ? `<button type="button" class="sbw-btn sbw-btn-lg sbw-pay-due" data-sbw="pay-due" ${paying ? 'disabled' : ''}>${paying ? 'Paying…' : `Pay ${money(p.due_amount)} ${esc(p.currency)}`}</button>` : ''}
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
    if (!busy) {
        minimized = false;
        mini.hidden = true;
    }
    if (!busy) {
        updateStep = null;
        liveProgress = null;
    }
}

// Two modes: 'update' (an install in progress / just finished) and 'download'
// (the package fetching in the background, then "ready — Install now").
function updateMini(view = null) {
    if (!minimized) return;
    const u = state?.update || {};
    const pkg = u.package || {};
    const installing = busy === 'update' || updateStep === 'done' || updateStep === 'failed';
    const set = (sel, text) => {
        const el = mini.querySelector(sel);
        if (el && el.textContent !== text) el.textContent = text;
    };
    let pct;
    let title;
    let sub;
    let mode;

    if (installing) {
        view = view || stagesView();
        const done = updateStep === 'done';
        const failed = updateStep === 'failed';
        mode = done ? 'done' : failed ? 'failed' : 'busy';
        pct = done ? 100 : view.overall;
        title = done ? `Updated to v${u.latest_version || ''}` : failed ? 'Update failed' : view.title;
        const running = view.stages.find((st) => st.status === 'running');
        sub = done ? 'Click to reload and use the new version' : failed ? 'Click to see what happened' : running ? `${running.label} ${running.pctLabel}` : 'Click to see details';
        set('.sbw-mini-pct', done ? '✓' : failed ? '!' : pct + '%');
    } else if (pkg.state === 'ready') {
        const n = updateCount(u);
        mode = 'ready';
        pct = 100;
        title = n > 1 ? `${n} updates ready` : `v${u.latest_version || ''} downloaded`;
        sub = n > 1 ? `v${u.latest_version} → v${targetVersion(u)} — installs in moments` : 'Ready to install — takes moments';
        set('.sbw-mini-pct', '✓');
    } else if (pkg.state === 'downloading') {
        const n = updateCount(u);
        mode = 'busy';
        pct = pkg.total ? Math.floor((100 * pkg.received) / pkg.total) : 0;
        title = n > 1 ? `Downloading update ${Math.min(n, (pkg.ready_count || 0) + 1)} of ${n}` : `Downloading v${u.latest_version || ''}`;
        const mb = (x) => (x / 1048576).toFixed(1);
        sub = (n > 1 && pkg.current ? `v${pkg.current} · ` : '') + (pkg.total ? `${mb(pkg.received)} of ${mb(pkg.total)} MB — you'll be notified` : "You'll be notified when done");
        set('.sbw-mini-pct', pkg.total ? pct + '%' : '…');
    } else {
        // Nothing to show any more (download failed / update gone): back to the modal.
        mode = 'failed';
        pct = 0;
        title = pkg.state === 'failed' ? 'Download failed' : 'Update';
        sub = 'Click to see details';
        set('.sbw-mini-pct', '!');
    }

    mini.dataset.mode = mode;
    set('.sbw-mini-title', title);
    set('.sbw-mini-sub', sub);
    mini.querySelector('.sbw-mini-act').hidden = mode !== 'ready';
    mini.querySelector('.sbw-mini-x').hidden = installing;
    mini.querySelector('.sbw-mini-bar span').style.width = pct + '%';
    mini.querySelector('.sbw-mini-bar').classList.toggle('sbw-bar-busy', mode === 'busy' && !installing && !pkg.total);
}

// The download finished while the user was elsewhere: say so, with a button.
function notifyPackageReady() {
    if (busy || !state?.update?.available || state.update.locked) return;
    if (!modal.hidden) return; // the open modal already switches to "Install now"

    // Everything is downloaded: pop the update up (a new event, so even after
    // "Later") — unless another dialog or the panel is in the way.
    if (payModal.hidden && noticeModal.hidden && !panelOpen) {
        minimized = false;
        mini.hidden = true;
        modalDismissedFor = null;
        showModal();
        return;
    }
    minimized = true;
    mini.hidden = false;
    updateMini();
    mini.classList.remove('sbw-mini-ping');
    void mini.offsetWidth; // restart the attention animation
    mini.classList.add('sbw-mini-ping');
}

// The server downloads the package in the background; while it does, look at
// its progress every few seconds instead of the usual 5 minutes.
let pkgPoll = null;
let lastPkgState = null;
function watchPackage() {
    const st = state?.update?.package?.state || null;
    if (lastPkgState === 'downloading' && st === 'ready') notifyPackageReady();
    lastPkgState = st;
    clearTimeout(pkgPoll);
    if (st === 'downloading' && !busy) {
        pkgPoll = setTimeout(() => (document.visibilityState === 'visible' ? refresh(true) : watchPackage()), 3000);
    }
}

function minimizeModal() {
    minimized = true;
    if (busy === 'update') saveRun({ minimized: true });
    modal.hidden = true;
    mini.hidden = false;
    updateMini();
}

function restoreModal() {
    if (updateStep === 'done') {
        window.location.reload();
        return;
    }
    minimized = false;
    mini.hidden = true;
    mini.classList.remove('sbw-mini-ping');
    if (busy === 'update') saveRun({ minimized: false });
    renderModal(state.update);
    modal.hidden = false;
}

/* ---- resume after a reload ----------------------------------------------
 * Each version step is driven from the page in chunks, tagged with a run id.
 * The run id is remembered, so after a reload (or the tab being reopened) the
 * page carries the SAME job on — the server continues where it stopped
 * instead of starting the update again. A heartbeat keeps two tabs from
 * driving one update at the same time.
 */
const RUN_KEY = 'subandl-widget:update-run';
const RESUME_MAX_AGE = 30 * 60 * 1000;
const tabId = (() => {
    try {
        let id = sessionStorage.getItem('subandl-widget:tab');
        if (!id) {
            id = Math.random().toString(36).slice(2, 10);
            sessionStorage.setItem('subandl-widget:tab', id);
        }
        return id;
    } catch (e) {
        return Math.random().toString(36).slice(2, 10);
    }
})();

function savedRun() {
    try {
        const v = JSON.parse(localStorage.getItem(RUN_KEY) || 'null');
        return v && v.run && Date.now() - (v.at || 0) < RESUME_MAX_AGE ? v : null;
    } catch (e) {
        return null;
    }
}

function saveRun(patch) {
    try {
        const v = { ...(savedRun() || {}), ...patch, tab: tabId, at: Date.now() };
        localStorage.setItem(RUN_KEY, JSON.stringify(v));
    } catch (e) {
        // Storage blocked: a reload just won't resume.
    }
}

function clearRun() {
    try {
        localStorage.removeItem(RUN_KEY);
    } catch (e) {
        // ignore
    }
}

let resumeChecked = false;

// After a reload: is there an update this page (tab) was driving? Carry it on.
async function maybeResume() {
    if (resumeChecked || busy || unlicensed()) return;
    const saved = savedRun();
    if (!saved && !state?.update?.running) return;
    resumeChecked = true;

    // Another open tab is driving it right now: leave it to that one.
    if (saved && saved.tab !== tabId && Date.now() - saved.at < 15000) return;

    const r = await api.updateStatus();
    const prog = r.ok ? r.data.progress : null;
    if (!prog || !prog.run) return;
    const failed = Object.values(prog.stages || {}).some((st) => st && st.status === 'failed');
    if (failed && !r.data.running) {
        clearRun();
        return;
    }
    const ours = saved?.run === prog.run;
    if (!ours) {
        // Not started from this browser: take it over only when nobody drives it
        // any more (its progress stood still for 15s) — another admin's browser
        // may be updating right now, and its page should stay in charge.
        if (!r.data.running) return;
        await new Promise((resolve) => setTimeout(resolve, 15000));
        if (busy) return;
        const again = await api.updateStatus();
        const now = again.ok ? again.data.progress : null;
        if (!again.ok || !again.data.running || !now || now.run !== prog.run || now.updated_at !== prog.updated_at) return;
    }

    runUpdate({ run: prog.run, progress: prog, minimized: ours && !!saved?.minimized });
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

    if (state.payment && !panelOpen && payModal.hidden && modal.hidden && noticeModal.hidden && !snoozed(DUE_SNOOZE_KEY)) {
        showPayModal();
        return;
    }

    if (!pageMode && !panelOpen && payModal.hidden && modal.hidden && noticeModal.hidden) {
        const next = unreadNotices().find((n) => n.popup);
        if (next) {
            showNoticeModal(next);
            return;
        }
    }

    const u = state.update;
    // "Updating…" on screen while this page is not the one updating: look again
    // every minute, so the Update button is back as soon as the server clears it.
    if (u?.running && busy !== 'update' && document.visibilityState === 'visible') refresh(true);
    // Still downloading in the background: no popup yet — a quiet pill shows the
    // progress, and the popup comes when everything is ready (notifyPackageReady).
    if (u?.available && !u.running && !u.force_update && !u.locked && u.package?.state === 'downloading' && modal.hidden && !busy) {
        if (!minimized && !pillDismissed) {
            minimized = true;
            mini.hidden = false;
            updateMini();
        }
        return;
    }
    if (u?.available && !u.running && !unlicensed() && modal.hidden && !minimized && payModal.hidden && noticeModal.hidden
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
    // Background download moving: patch the open modal / the pill in place.
    if (!busy && state.update && !updateStep) {
        if (!modal.hidden) renderModal(state.update);
        updateMini();
    }
    watchPackage();
    // Not awaited: a takeover check may wait 15s, and nothing below depends on
    // it (autoPrompt never offers "Update now" while an update is running).
    maybeResume();
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
    const hasContent = hasLicenseTab() || hasUpdateTab() || hasNoticeTab();
    root.hidden = !hasContent || isHiddenHere();
    if (root.hidden) {
        root.classList.remove('sbw-open');
        panelOpen = false;
        lockScroll(false);
        hideModal();
        payModal.hidden = true;
        noticeModal.hidden = true;
        noticeShown = null;
    }
    renderBanner();
}

async function runUpdate(resume = null) {
    if (busy || unlicensed()) return;
    busy = 'update';
    updateStep = 'running';
    liveProgress = resume?.progress || null;
    liveStepNo = 1;
    renderModal(state.update, resume ? 'Continuing the update where it stopped…' : null);
    render();
    if (resume?.minimized) {
        minimizeModal();
    } else {
        minimized = false;
        mini.hidden = true;
        modal.hidden = false;
    }

    // 1. The server confirms the provider is reachable before anything changes
    //    (not again when carrying on a job that already passed it).
    const pre = resume ? { ok: true } : await api.preflight();
    if (!pre.ok) {
        busy = null;
        updateStep = 'failed';
        liveProgress = { current: 'check', stages: { check: { status: 'failed', percent: 0, detail: pre.message || null } } };
        renderModal(state.update, null, pre.message || 'The update server is not reachable. Please try again later.');
        refresh(true);
        return;
    }

    // 2. Backup, download, merge and migrate, one version step at a time;
    //    every stage reports its own percentage while the step runs.
    let note = resume ? 'Continuing the update where it stopped…' : null;
    let beat = 0;
    const result = await api.runUpdate(({ message, step, progress, version, more }) => {
        if (Date.now() - beat > 3000) {
            beat = Date.now();
            saveRun({}); // heartbeat: this tab is driving the update
        }
        if (step && step !== liveStepNo) liveProgress = null;
        liveStepNo = step || liveStepNo;
        if (progress) liveProgress = mergeProgress(liveProgress, progress);
        // Keep "Connection lost, retrying…" / "Waiting…" on screen until real progress
        // arrives again, so a slow line doesn't look frozen.
        if (version && more) note = `${message} Continuing with the next version…`;
        else if (!progress && message) note = message;
        else if (progress && note && !version) note = null;
        renderModal(state.update, note);
        updateMini();
    }, {
        resumeRun: resume?.run || null,
        onRun: (run) => saveRun({ run, minimized }),
    });
    busy = null;
    clearRun();
    if (result.progress) liveProgress = mergeProgress(liveProgress, result.progress);

    if (result.ok) {
        updateStep = 'done';
        if (minimized) {
            // The user is working: don't yank the page away, let them reload when ready.
            renderModal(state.update, (result.message || 'Update applied.') + ' Reload to use the new version.');
            updateMini();
            return;
        }
        renderModal(state.update, (result.message || 'Update applied.') + ' Reloading…');
        setTimeout(() => window.location.reload(), 1800);
        return;
    }

    updateStep = 'failed';
    renderModal(state.update, null, result.message || 'Update failed.');
    if (minimized) restoreModal();
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

/*
 * Pays an invoice on the provider's site in a popup window, so the user
 * never leaves this app. The popup's last page posts
 * { type: 'subandl-payment-done', success, transaction_id } back here and
 * closes; then the license is re-checked and the page shows the new status.
 * Closing the popup early just re-checks, in case it was paid anyway.
 */
function payOnline(url, openUrl = url) {
    if (paying || !url) return;

    const w = 520;
    const h = 720;
    const left = Math.max(0, window.screenX + (window.outerWidth - w) / 2);
    const top = Math.max(0, window.screenY + (window.outerHeight - h) / 2);
    const popup = window.open(openUrl, 'subandl_payment', `popup=yes,width=${w},height=${h},left=${left},top=${top}`);

    if (!popup) {
        flash = { tone: 'bad', text: 'Your browser blocked the payment window. Allow pop-ups for this site and try again.' };
        rerenderAll();
        return;
    }
    popup.focus();

    let origin = '';
    try {
        origin = new URL(url, window.location.href).origin;
    } catch (e) {
        origin = '';
    }

    paying = url;
    flash = { tone: 'info', text: 'Complete the payment in the window that opened…' };
    rerenderAll();

    let result = null;
    const onMessage = (event) => {
        if (event.origin !== origin || event.data?.type !== 'subandl-payment-done') return;
        result = event.data;
        // Close the payment window from here too (it also closes itself).
        try {
            popup.close();
        } catch (e) {}
        finish();
    };
    window.addEventListener('message', onMessage);
    const watcher = setInterval(() => popup.closed && finish(), 700);

    let finished = false;
    async function finish() {
        if (finished) return;
        finished = true;
        clearInterval(watcher);
        window.removeEventListener('message', onMessage);

        const wasBlocked = !!state?.payment?.blocked;
        flash = { tone: 'info', text: 'Checking the payment…' };
        rerenderAll();

        // Ask the provider again: the payment shows up in the license now.
        const r = await api.refreshLicense();
        if (r.ok) status = r.data;
        await refresh(true);
        paying = null;

        if (result?.success) {
            const amt = result.amount ? ` ${money(result.amount)} ${esc(state?.account?.wallet?.currency || '')}`.trimEnd() : '';
            flash = { tone: 'ok', text: `Payment of${amt || ' the amount'} received${result.transaction_id ? `. Transaction ID: ${result.transaction_id}` : ''}.` };
        } else if (result) {
            flash = { tone: 'bad', text: result.message || 'The payment was not completed.' };
        } else {
            flash = state?.payment ? null : { tone: 'ok', text: 'Payment received.' };
        }

        // Paid: the reminder popup closes (the panel keeps the new status and
        // the message). Only "Remind me later"'s pause applies to it again.
        if (result?.success || !state?.payment) {
            payModal.hidden = true;
            snooze(DUE_SNOOZE_KEY, (cfg.reminderMinutes || 10) * 60000);
        }
        rerenderAll();

        // Access was paused and is back: carry on where the app was.
        if (wasBlocked && state && !state.payment?.blocked && !state.license?.needs_redirect && pageMode && state.urls?.home) {
            window.location.assign(state.urls.home);
        }
    }
}

// What still has to be paid: the due minus what the wallet already holds
// (the wallet pays dues first). At least 10, the gateway minimum; '' when
// the wallet already covers it.
function amountNeeded(due) {
    const balance = Math.max(0, Number(state?.account?.wallet?.balance) || 0);
    const need = Math.round(((Number(due) || 0) - balance) * 100) / 100;
    return need <= 0 ? '' : Math.max(10, need);
}

// The reminder popup's "pay online" box: an editable amount, pre-filled with
// what is still needed after the wallet balance.
function renderPayDueForm(p) {
    const w = state?.account?.wallet;
    if (!w?.deposit_url) return '';
    const cur = esc(w.currency || p.currency || 'BDT');
    const balance = Number(w.balance) || 0;
    const need = amountNeeded(p.due_amount);

    return `
        <form class="sbw-paydue" data-sbw-form="deposit">
            <label class="sbw-label" for="sbw-paydue-amount">Pay online</label>
            <div class="sbw-account-row">
                <input id="sbw-paydue-amount" class="sbw-input" type="number" name="amount" min="10" step="0.01" inputmode="decimal" value="${esc(need)}" placeholder="Amount (${cur})">
                <button class="sbw-btn sbw-btn-lg sbw-pay-due" type="submit" ${paying ? 'disabled' : ''}>${paying ? 'Paying…' : 'Pay now'}</button>
            </div>
            <p class="sbw-muted">${balance > 0
                ? `Due ${money(p.due_amount)} ${cur} − wallet ${money(balance)} ${cur} = ${need ? `${money(need)} ${cur} to pay` : 'already covered by the wallet'}. You can change the amount.`
                : 'You can change the amount; anything extra stays in your wallet for the next month.'}</p>
        </form>`;
}

// "Pay now" for the whole due: into the wallet (it pays the due invoices
// from there, oldest first), or — from a provider without wallet links —
// the oldest invoice's own link.
function payDueTarget(p) {
    const due = Number(p?.due_amount) || 0;
    const wallet = state?.account?.wallet?.deposit_url;
    if (wallet && due > 0) return { key: wallet, url: direct(wallet, { amount: amountNeeded(due) || due }) };
    const inv = (p?.invoices || []).find((i) => i.pay_url);
    return inv ? { key: inv.pay_url, url: direct(inv.pay_url, { pay: 1 }) } : null;
}

// A provider pay link plus what makes it skip the provider's own page and
// open the payment gateway at once (`amount` for the wallet, `pay` for an invoice).
// Older providers ignore these and show their page, as before.
function direct(url, params) {
    const q = Object.entries(params).map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&');
    return url + (url.includes('?') ? '&' : '?') + q;
}

// Panel and (when open) the payment popup show the same invoice list.
function rerenderAll() {
    render();
    if (!payModal.hidden && state?.payment) renderPayModal(state.payment);
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
        case 'update-minimize':
            minimizeModal();
            break;
        case 'update-restore':
            restoreModal();
            break;
        case 'update-run-mini':
            restoreModal();
            runUpdate();
            break;
        case 'update-mini-close':
            minimized = false;
            mini.hidden = true;
            pillDismissed = true;
            break;
        case 'update-subscribe':
            modalDismissedFor = state.update.latest_version;
            hideModal();
            openPanel('license');
            break;
        case 'notice-ok':
            hideNoticeModal();
            autoPrompt();
            break;
        case 'notice-all':
            hideNoticeModal();
            openPanel('notices');
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
        case 'pay-due': {
            const target = payDueTarget(state?.payment);
            if (target) payOnline(target.key, target.url);
            break;
        }
        case 'pay-online': {
            const url = event.target.closest('[data-sbw]').dataset.url;
            payOnline(url, direct(url, { pay: 1 }));
            break;
        }
        case 'deposit-chip': {
            const input = root.querySelector('#sbw-deposit-amount');
            if (input) {
                input.value = event.target.closest('[data-sbw]').dataset.amount;
                input.focus();
            }
            break;
        }
    }
});

root.addEventListener('submit', (event) => {
    const deposit = event.target.closest('[data-sbw-form="deposit"]');
    if (deposit) {
        event.preventDefault();
        const amount = Number(deposit.elements.amount.value);
        const url = state?.account?.wallet?.deposit_url;
        if (!url) return;
        if (!(amount >= 10)) {
            flash = { tone: 'bad', text: 'Enter an amount of at least 10.' };
            render();
            return;
        }
        // The provider signs the link without `amount`, so adding it keeps the
        // link valid; with it, the window goes straight to the payment gateway.
        payOnline(url, direct(url, { amount }));
        return;
    }

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
    } else if (!noticeModal.hidden) {
        hideNoticeModal();
    } else if (!modal.hidden && busy === 'update') {
        minimizeModal();
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
