@extends('subandl::layout')

@section('title', 'Subscription')

@php
    $base = rtrim(url(config('subandl.routes.prefix', '')), '/');
@endphp

@section('content')
    <h1>Subscription</h1>
    <div class="muted">{{ config('app.name') }} · version <strong id="current-version">{{ $currentVersion }}</strong></div>

    <nav class="tabs">
        <a href="{{ route('subscription.license') }}" class="{{ $tab === 'license' ? 'active' : '' }}">License</a>
        @if ($canUpdate || $canBackup)
            <a href="{{ route('subscription.update') }}" class="{{ $tab !== 'license' ? 'active' : '' }}">Update &amp; Backup</a>
        @endif
    </nav>

    <div id="flash"></div>

    @if ($tab === 'license')
        <section class="card">
            <h2>License</h2>
            <div class="grid" id="license-grid">
                <div><div class="label">Status</div><div class="value" data-f="status">…</div></div>
                <div><div class="label">Client</div><div class="value" data-f="client_name">…</div></div>
                <div><div class="label">Plan</div><div class="value" data-f="subscription_type">…</div></div>
                <div><div class="label">Expires</div><div class="value" data-f="expires_at">…</div></div>
                <div><div class="label">Update support until</div><div class="value" data-f="update_support_expires_at">…</div></div>
                <div><div class="label">Due amount</div><div class="value" data-f="due_amount">…</div></div>
                <div><div class="label">Last verified</div><div class="value" data-f="last_verified_at">…</div></div>
            </div>
            <p class="bad" data-f="message" style="margin:12px 0 0"></p>
        </section>

        <section class="card">
            <h2>License key</h2>
            <form id="license-form" class="row">
                <input type="text" name="license" autocomplete="off" placeholder="XXXX-XXXX-XXXX-XXXX" value="{{ $state->license_key }}">
                <button type="submit">Save &amp; verify</button>
                <button type="button" class="ghost" id="refresh-btn">Refresh</button>
            </form>
            <p class="muted" style="margin:10px 0 0">By using this software you agree to the <a href="{{ route('license.terms') }}">terms</a>.</p>
        </section>
    @else
        @if ($canUpdate)
            <section class="card">
                <h2>Software update</h2>
                <p id="update-info" class="muted">Last check: <span data-f="last_update_check_at">…</span> · Last result: <span data-f="last_update_message">—</span></p>
                <div class="row">
                    <button type="button" class="ghost" id="check-update-btn">Check for update</button>
                    <button type="button" id="run-update-btn">Update now</button>
                </div>
            </section>
        @endif

        @if ($canBackup)
            <section class="card">
                <h2>Backup</h2>
                <label class="row" style="margin-bottom:12px">
                    <input type="checkbox" id="backup-toggle" style="flex:0 0 auto" @checked($state->backup_enabled)>
                    Automatic cloud backup (every {{ config('subandl.backup_interval_hours', 6) }} hours)
                </label>
                <p class="muted">Last backup: <span data-f="last_backup_at">…</span> <span data-f="last_backup_status"></span> · Next: <span data-f="next_backup_at">…</span></p>
                <button type="button" id="run-backup-btn">Backup now</button>
            </section>
        @endif

        <section class="card">
            <h2>History</h2>
            <table>
                <thead><tr><th>When</th><th>Type</th><th>Result</th><th>Details</th></tr></thead>
                <tbody id="history-body"><tr><td colspan="4" class="muted">Loading…</td></tr></tbody>
            </table>
        </section>
    @endif
@endsection

@push('scripts')
<script>
(() => {
    const S = window.subandl;
    const base = @json($base);
    const tones = { active: 'ok', expired: 'warn', unverified: 'warn', invalid: 'bad', tampered: 'bad' };
    const fmt = (v) => (v === null || v === undefined || v === '') ? '—' : v;
    const $ = (id) => document.getElementById(id);

    function paint(st) {
        document.querySelectorAll('[data-f]').forEach((el) => {
            const key = el.dataset.f;
            if (key === 'status') {
                el.innerHTML = '';
                const b = document.createElement('span');
                b.className = 'badge ' + (tones[st.status] || 'warn');
                b.textContent = st.status + (st.in_grace_period ? ' (grace)' : '');
                el.appendChild(b);
            } else if (key === 'message') {
                el.textContent = st.message || '';
            } else {
                el.textContent = fmt(st[key]);
            }
        });
    }

    async function loadStatus() {
        const r = await S.request('GET', base + '/license/status');
        if (r.ok) paint(r.data);
        return r.data;
    }

    async function loadHistory() {
        const body = $('history-body');
        if (!body) return;
        const [u, b] = await Promise.all([
            S.request('GET', base + '/license/update/history'),
            S.request('GET', base + '/license/backup/history'),
        ]);
        const rows = [
            ...((u.data.history) || []).map((h) => ({ at: h.created_at, type: 'Update', ok: h.ok, msg: (h.from_version || '?') + ' → ' + (h.to_version || '?') + ' · ' + (h.message || '') })),
            ...((b.data.history) || []).map((h) => ({ at: h.created_at, type: 'Backup', ok: h.ok, msg: h.message || '' })),
        ].sort((a, c) => (c.at || '').localeCompare(a.at || '')).slice(0, 30);

        body.innerHTML = '';
        if (!rows.length) { body.innerHTML = '<tr><td colspan="4" class="muted">No history yet.</td></tr>'; return; }
        rows.forEach((r) => {
            const tr = document.createElement('tr');
            [r.at, r.type, r.ok ? 'Success' : 'Failed', r.msg].forEach((text, i) => {
                const td = document.createElement('td');
                td.textContent = fmt(text);
                if (i === 2) td.className = r.ok ? 'ok' : 'bad';
                tr.appendChild(td);
            });
            body.appendChild(tr);
        });
    }

    async function busy(btn, fn) {
        btn.disabled = true;
        try { await fn(); } finally { btn.disabled = false; }
    }

    async function poll(url, key) {
        for (let i = 0; i < 360; i++) {
            await new Promise((r) => setTimeout(r, 5000));
            const r = await S.request('GET', url);
            if (r.ok && !r.data[key]) return r.data;
        }
    }

    const form = $('license-form');
    if (form) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const btn = form.querySelector('button[type=submit]');
            busy(btn, async () => {
                const r = await S.request('POST', base + '/license/save', { license: form.license.value });
                S.flash(r.data.message || (r.ok ? 'Saved.' : 'Could not verify the license.'), r.ok ? 'ok' : 'bad');
                await loadStatus();
            });
        });
        $('refresh-btn').addEventListener('click', (e) => busy(e.target, async () => {
            const r = await S.request('POST', base + '/license/check');
            if (r.ok) { paint(r.data); S.flash('License refreshed.', 'ok'); }
        }));
    }

    const checkBtn = $('check-update-btn');
    if (checkBtn) checkBtn.addEventListener('click', () => busy(checkBtn, async () => {
        const r = await S.request('POST', base + '/license/update/check');
        const d = r.data;
        S.flash(d.update_available ? ('Version ' + d.latest_version + ' is available.' + (d.changelog ? '\n' + d.changelog : '')) : (d.message || 'You are on the latest version.'), d.update_available ? 'warn' : 'ok');
        loadStatus();
    }));

    const runBtn = $('run-update-btn');
    if (runBtn) runBtn.addEventListener('click', () => busy(runBtn, async () => {
        if (!confirm('Apply the update now? The application may be briefly unavailable.')) return;
        // One version step per request; keep going while the server reports more.
        for (let step = 0; step < 20; step++) {
            S.flash('Updating… do not close this page.', 'warn');
            const r = await S.request('POST', base + '/license/update/run');
            if (r.status === 409) { S.flash(r.data.message, 'warn'); return; }
            if (!r.ok && r.status >= 500) {
                const st = await poll(base + '/license/update/status', 'running');
                S.flash((st && st.last_update_message) || 'Update request was interrupted.', st && st.last_update_status === 'success' ? 'ok' : 'bad');
                break;
            }
            if (!r.data.status) { S.flash(r.data.message || 'Update failed.', 'bad'); break; }
            if (r.data.version) $('current-version').textContent = r.data.version;
            S.flash(r.data.message, 'ok');
            if (!r.data.update_available) break;
        }
        loadStatus(); loadHistory();
    }));

    const toggle = $('backup-toggle');
    if (toggle) toggle.addEventListener('change', async () => {
        const r = await S.request('POST', base + '/license/backup-toggle', { enabled: toggle.checked });
        S.flash(r.ok ? ('Automatic backup ' + (toggle.checked ? 'enabled.' : 'disabled.')) : 'Could not change the setting.', r.ok ? 'ok' : 'bad');
    });

    const backupBtn = $('run-backup-btn');
    if (backupBtn) backupBtn.addEventListener('click', () => busy(backupBtn, async () => {
        const r = await S.request('POST', base + '/license/backup/run');
        if (!r.ok) { S.flash(r.data.message || 'Backup failed.', 'bad'); return; }
        if (r.data.running) {
            S.flash('Backup running…', 'warn');
            const st = await poll(base + '/license/backup/status', 'running');
            S.flash((st && st.last_backup_message) || 'Backup finished.', st && st.last_backup_status === 'success' ? 'ok' : 'bad');
        } else {
            S.flash(r.data.message || 'Backup finished.', r.data.ok ? 'ok' : 'bad');
        }
        loadStatus(); loadHistory();
    }));

    S.reportHealth(@json(config('subandl.provider_url')));
    loadStatus();
    loadHistory();
})();
</script>
@endpush
