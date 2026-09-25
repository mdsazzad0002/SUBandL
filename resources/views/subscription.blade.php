@extends('subandl::layout')

@section('title', 'Subscription')

@section('content')
    <h1>Subscription</h1>
    <div class="sb-muted">{{ config('app.name') }} · version <strong id="sb-version">{{ $currentVersion }}</strong></div>

    <nav class="sb-tabs">
        <a href="{{ $urls['license'] }}" class="{{ $tab === 'license' ? 'sb-active' : '' }}">License</a>
        @if ($canUpdate || $canBackup)
            <a href="{{ $urls['update'] }}" class="{{ $tab !== 'license' ? 'sb-active' : '' }}">Update &amp; Backup</a>
        @endif
    </nav>

    <div id="sb-flash" class="sb-flash" hidden></div>

    @if ($tab === 'license')
        <section class="sb-card">
            <h2>License</h2>
            <div class="sb-grid" id="sb-license-grid"></div>
            <p class="sb-bad" data-f="message"></p>
        </section>

        <section class="sb-card">
            <h2>License key</h2>
            <form id="sb-license-form" class="sb-row">
                <input class="sb-input" type="text" name="license" autocomplete="off" placeholder="XXXX-XXXX-XXXX-XXXX" value="{{ $licenseKey }}">
                <button class="sb-btn" type="submit">Save &amp; verify</button>
                <button class="sb-btn sb-ghost" type="button" id="sb-refresh">Refresh</button>
            </form>
            <p class="sb-muted">By using this software you agree to the <a href="{{ $urls['terms'] }}">terms</a>.</p>
        </section>
    @else
        @if ($canUpdate)
            <section class="sb-card">
                <h2>Software update</h2>
                <p class="sb-muted">Last check: <span data-f="last_update_check_at">…</span> · Last result: <span data-f="last_update_message">…</span></p>
                <div class="sb-row">
                    <button class="sb-btn sb-ghost" type="button" id="sb-check-update">Check for update</button>
                    <button class="sb-btn" type="button" id="sb-run-update">Update now</button>
                </div>
            </section>
        @endif

        @if ($canBackup)
            <section class="sb-card">
                <h2>Backup</h2>
                <label class="sb-row" style="margin-bottom:12px">
                    <input type="checkbox" id="sb-backup-toggle" @checked($backupEnabled)>
                    Automatic cloud backup (every {{ $backupIntervalHours }} hours)
                </label>
                <p class="sb-muted">Last backup: <span data-f="last_backup_at">…</span> <span data-f="last_backup_status"></span> · Next: <span data-f="next_backup_at">…</span></p>
                <button class="sb-btn" type="button" id="sb-run-backup">Backup now</button>
            </section>
        @endif

        <section class="sb-card">
            <h2>History</h2>
            <table class="sb-table">
                <thead><tr><th>When</th><th>Type</th><th>Result</th><th>Details</th></tr></thead>
                <tbody id="sb-history"><tr><td colspan="4" class="sb-muted">Loading…</td></tr></tbody>
            </table>
        </section>
    @endif
@endsection

@push('scripts')
<script type="module">
{{-- Shared core (same file the Vue/React pages import). --}}
{!! \SUBandL\Support\Assets::inline('subandl.js') !!}

const props = @json(['base' => $base, 'providerUrl' => $providerUrl, 'tab' => $tab]);
const api = createSubandl({ base: props.base });
const $ = (id) => document.getElementById(id);
const el = (tag, text, className) => Object.assign(document.createElement(tag), { textContent: text ?? '', className: className ?? '' });

function say(message, tone) {
    const box = $('sb-flash');
    box.textContent = message || '';
    box.className = 'sb-flash' + (tone ? ' sb-' + tone : '');
    box.hidden = !message;
}

async function busy(btn, fn) {
    btn.disabled = true;
    try { await fn(); } finally { btn.disabled = false; }
}

function paint(st) {
    const grid = $('sb-license-grid');
    if (grid) {
        grid.replaceChildren(...LICENSE_FIELDS.map(([key, label]) => {
            const cell = document.createElement('div');
            const value = el('div', '', 'sb-value');
            if (key === 'status') {
                value.append(el('span', fmt(st.status) + (st.in_grace_period ? ' (grace)' : ''), 'sb-badge sb-' + statusTone(st.status)));
            } else {
                value.textContent = fmt(st[key]);
            }
            cell.append(el('div', label, 'sb-label'), value);
            return cell;
        }));
    }
    document.querySelectorAll('[data-f]').forEach((node) => {
        const key = node.dataset.f;
        node.textContent = key === 'message' || key === 'last_backup_status' ? (st[key] || '') : fmt(st[key]);
    });
}

async function loadStatus() {
    const r = await api.status();
    if (r.ok) paint(r.data);
}

async function loadHistory() {
    const body = $('sb-history');
    if (!body) return;
    const rows = await api.history();
    if (!rows.length) {
        const tr = document.createElement('tr');
        tr.append(Object.assign(el('td', 'No history yet.', 'sb-muted'), { colSpan: 4 }));
        body.replaceChildren(tr);
        return;
    }
    body.replaceChildren(...rows.map((h) => {
        const tr = document.createElement('tr');
        tr.append(el('td', fmt(h.at)), el('td', h.type), el('td', h.ok ? 'Success' : 'Failed', h.ok ? 'sb-ok' : 'sb-bad'), el('td', fmt(h.message)));
        return tr;
    }));
}

const form = $('sb-license-form');
if (form) {
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        busy(form.querySelector('[type=submit]'), async () => {
            const r = await api.saveLicense(form.license.value);
            say(r.message, r.ok ? 'ok' : 'bad');
            await loadStatus();
        });
    });
    $('sb-refresh').addEventListener('click', (e) => busy(e.currentTarget, async () => {
        const r = await api.refreshLicense();
        if (r.ok) { paint(r.data); say('License refreshed.', 'ok'); }
    }));
}

$('sb-check-update')?.addEventListener('click', (e) => busy(e.currentTarget, async () => {
    const d = await api.checkUpdate();
    say(d.update_available
        ? `Version ${d.latest_version} is available.${d.changelog ? '\n' + d.changelog : ''}`
        : (d.message || 'You are on the latest version.'), d.update_available ? 'warn' : 'ok');
    loadStatus();
}));

$('sb-run-update')?.addEventListener('click', (e) => busy(e.currentTarget, async () => {
    if (!confirm('Apply the update now? The application may be briefly unavailable.')) return;
    const r = await api.runUpdate((p) => {
        if (p.version) $('sb-version').textContent = p.version;
        say(p.message, p.version ? 'ok' : 'warn');
    });
    if (r.version) $('sb-version').textContent = r.version;
    say(r.message, r.ok ? 'ok' : 'bad');
    loadStatus(); loadHistory();
}));

const toggle = $('sb-backup-toggle');
toggle?.addEventListener('change', async () => {
    const r = await api.toggleBackup(toggle.checked);
    toggle.checked = r.enabled;
    say(r.ok ? `Automatic backup ${r.enabled ? 'enabled' : 'disabled'}.` : 'Could not change the setting.', r.ok ? 'ok' : 'bad');
});

$('sb-run-backup')?.addEventListener('click', (e) => busy(e.currentTarget, async () => {
    const r = await api.runBackup((p) => say(p.message, 'warn'));
    say(r.message, r.ok ? 'ok' : 'bad');
    loadStatus(); loadHistory();
}));

api.reportHealth(props.providerUrl);
loadStatus();
loadHistory();
</script>
@endpush
