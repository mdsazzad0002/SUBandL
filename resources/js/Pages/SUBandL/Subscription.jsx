// SUBandL subscription page (React / Inertia). Logic lives in ../../subandl/subandl.js,
// shared with the Blade and Vue UIs — keep this file presentation-only.
import React, { useEffect, useMemo, useState } from 'react';
import { createSubandl, fmt, statusTone, LICENSE_FIELDS } from '../../subandl/subandl.js';
import '../../subandl/subandl.css';

export default function Subscription({
    currentVersion,
    tab = 'license',
    canUpdate = false,
    canBackup = false,
    licenseKey = '',
    backupEnabled = false,
    backupIntervalHours,
    providerUrl,
    base = '',
    urls = {},
}) {
    const api = useMemo(() => createSubandl({ base }), [base]);
    const [status, setStatus] = useState({});
    const [history, setHistory] = useState([]);
    const [flash, setFlash] = useState({ message: '', tone: '' });
    const [busy, setBusy] = useState({});
    const [license, setLicense] = useState(licenseKey || '');
    const [backupOn, setBackupOn] = useState(!!backupEnabled);
    const [version, setVersion] = useState(currentVersion);

    const say = (message, tone = '') => setFlash({ message, tone });

    const withBusy = (key, fn) => async (...args) => {
        setBusy((b) => ({ ...b, [key]: true }));
        try { await fn(...args); } finally { setBusy((b) => ({ ...b, [key]: false })); }
    };

    const loadStatus = async () => {
        const r = await api.status();
        if (r.ok) setStatus(r.data);
    };

    const loadHistory = async () => {
        if (tab !== 'license') setHistory(await api.history());
    };

    useEffect(() => {
        api.reportHealth(providerUrl);
        loadStatus();
        loadHistory();
    }, [api]);

    const save = withBusy('save', async (e) => {
        e.preventDefault();
        const r = await api.saveLicense(license);
        say(r.message, r.ok ? 'ok' : 'bad');
        await loadStatus();
    });

    const refresh = withBusy('refresh', async () => {
        const r = await api.refreshLicense();
        if (r.ok) { setStatus(r.data); say('License refreshed.', 'ok'); }
    });

    const checkUpdate = withBusy('check', async () => {
        const d = await api.checkUpdate();
        say(d.update_available
            ? `Version ${d.latest_version} is available.${d.changelog ? '\n' + d.changelog : ''}`
            : (d.message || 'You are on the latest version.'), d.update_available ? 'warn' : 'ok');
        loadStatus();
    });

    const runUpdate = withBusy('update', async () => {
        if (!window.confirm('Apply the update now? The application may be briefly unavailable.')) return;
        const r = await api.runUpdate((p) => {
            if (p.version) setVersion(p.version);
            say(p.message, p.version ? 'ok' : 'warn');
        });
        if (r.version) setVersion(r.version);
        say(r.message, r.ok ? 'ok' : 'bad');
        loadStatus(); loadHistory();
    });

    const toggleBackup = async (e) => {
        const r = await api.toggleBackup(e.target.checked);
        setBackupOn(r.enabled);
        say(r.ok ? `Automatic backup ${r.enabled ? 'enabled' : 'disabled'}.` : 'Could not change the setting.', r.ok ? 'ok' : 'bad');
    };

    const runBackup = withBusy('backup', async () => {
        const r = await api.runBackup((p) => say(p.message, 'warn'));
        say(r.message, r.ok ? 'ok' : 'bad');
        loadStatus(); loadHistory();
    });

    return (
        <div className="subandl">
            <h1>Subscription</h1>
            <div className="sb-muted">Version <strong>{version}</strong></div>

            <nav className="sb-tabs">
                <a href={urls.license} className={tab === 'license' ? 'sb-active' : ''}>License</a>
                {(canUpdate || canBackup) && (
                    <a href={urls.update} className={tab !== 'license' ? 'sb-active' : ''}>Update &amp; Backup</a>
                )}
            </nav>

            {flash.message && <div className={'sb-flash' + (flash.tone ? ' sb-' + flash.tone : '')}>{flash.message}</div>}

            {tab === 'license' ? (
                <>
                    <section className="sb-card">
                        <h2>License</h2>
                        <div className="sb-grid">
                            {LICENSE_FIELDS.map(([key, label]) => (
                                <div key={key}>
                                    <div className="sb-label">{label}</div>
                                    <div className="sb-value">
                                        {key === 'status' ? (
                                            <span className={'sb-badge sb-' + statusTone(status.status)}>
                                                {fmt(status.status)}{status.in_grace_period ? ' (grace)' : ''}
                                            </span>
                                        ) : fmt(status[key])}
                                    </div>
                                </div>
                            ))}
                        </div>
                        {status.message && <p className="sb-bad">{status.message}</p>}
                    </section>

                    <section className="sb-card">
                        <h2>License key</h2>
                        <form className="sb-row" onSubmit={save}>
                            <input className="sb-input" value={license} onChange={(e) => setLicense(e.target.value)} autoComplete="off" placeholder="XXXX-XXXX-XXXX-XXXX" />
                            <button className="sb-btn" type="submit" disabled={busy.save}>Save &amp; verify</button>
                            <button className="sb-btn sb-ghost" type="button" disabled={busy.refresh} onClick={refresh}>Refresh</button>
                        </form>
                        <p className="sb-muted">By using this software you agree to the <a href={urls.terms}>terms</a>.</p>
                    </section>
                </>
            ) : (
                <>
                    {canUpdate && (
                        <section className="sb-card">
                            <h2>Software update</h2>
                            <p className="sb-muted">
                                Last check: {fmt(status.last_update_check_at)} · Last result: {fmt(status.last_update_message)}
                            </p>
                            <div className="sb-row">
                                <button className="sb-btn sb-ghost" disabled={busy.check} onClick={checkUpdate}>Check for update</button>
                                <button className="sb-btn" disabled={busy.update} onClick={runUpdate}>Update now</button>
                            </div>
                        </section>
                    )}

                    {canBackup && (
                        <section className="sb-card">
                            <h2>Backup</h2>
                            <label className="sb-row" style={{ marginBottom: 12 }}>
                                <input type="checkbox" checked={backupOn} onChange={toggleBackup} />
                                Automatic cloud backup (every {backupIntervalHours} hours)
                            </label>
                            <p className="sb-muted">
                                Last backup: {fmt(status.last_backup_at)} {status.last_backup_status || ''} · Next: {fmt(status.next_backup_at)}
                            </p>
                            <button className="sb-btn" disabled={busy.backup} onClick={runBackup}>Backup now</button>
                        </section>
                    )}

                    <section className="sb-card">
                        <h2>History</h2>
                        <table className="sb-table">
                            <thead><tr><th>When</th><th>Type</th><th>Result</th><th>Details</th></tr></thead>
                            <tbody>
                                {!history.length && <tr><td colSpan={4} className="sb-muted">No history yet.</td></tr>}
                                {history.map((h) => (
                                    <tr key={h.key}>
                                        <td>{fmt(h.at)}</td>
                                        <td>{h.type}</td>
                                        <td className={h.ok ? 'sb-ok' : 'sb-bad'}>{h.ok ? 'Success' : 'Failed'}</td>
                                        <td>{fmt(h.message)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                </>
            )}
        </div>
    );
}
