/**
 * SUBandL shared client core — framework-agnostic.
 *
 * Every UI (Blade, Vue, React) goes through this file for API calls, the
 * multi-step update / backup flows and display helpers, so behaviour is
 * fixed in one place. Blade inlines it; Vue/React pages import it.
 *
 * No dependencies; uses fetch.
 */

export const STATUS_TONES = {
    active: 'ok',
    expired: 'warn',
    unverified: 'warn',
    invalid: 'bad',
    tampered: 'bad',
};

export function statusTone(status) {
    return STATUS_TONES[status] || 'warn';
}

export function fmt(value) {
    return value === null || value === undefined || value === '' ? '—' : String(value);
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** The update stages in order, as the server reports them (see UpdateProgress.php). */
export const UPDATE_STAGES = [
    ['check', 'Check'],
    ['backup', 'Backup'],
    ['download', 'Download'],
    ['merge', 'Merge files'],
    ['migrate', 'Migrate'],
];

/**
 * The server's progress snapshot as a list of
 * { key, label, status: pending|running|done|skipped|failed, percent, detail }.
 * Without a snapshot every stage is pending.
 */
export function updateStages(progress) {
    const stages = (progress && progress.stages) || {};
    return UPDATE_STAGES.map(([key, label]) => ({
        key,
        label,
        status: 'pending',
        percent: 0,
        detail: null,
        ...(stages[key] || {}),
    }));
}

/** One line for UIs without bars: "Download 45% — 3.2 MB of 7.0 MB". */
export function progressText(progress) {
    const stage = updateStages(progress).find((s) => s.key === (progress && progress.current)) || null;
    if (!stage) return 'Updating… do not close this page.';
    return `${stage.label} ${stage.percent}%` + (stage.detail ? ' — ' + stage.detail : '');
}

function csrfHeaders() {
    const meta = typeof document !== 'undefined' && document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) {
        return { 'X-CSRF-TOKEN': meta.content };
    }

    // Inertia/SPA apps often only have Laravel's XSRF-TOKEN cookie.
    const match = typeof document !== 'undefined' && document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {};
}

/**
 * @param {{ base?: string }} options  base = the routes prefix URL ('' by default)
 */
export function createSubandl({ base = '' } = {}) {
    const root = String(base).replace(/\/+$/, '');

    async function request(method, path, body) {
        let res;
        try {
            res = await fetch(root + path, {
                method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                body: body === undefined ? undefined : JSON.stringify(body),
            });
        } catch (e) {
            return { ok: false, status: 0, data: { message: 'Network error: ' + e.message } };
        }

        let data = {};
        try {
            data = await res.json();
        } catch (e) {
            // Non-JSON (e.g. a proxy error page).
        }

        return { ok: res.ok, status: res.status, data };
    }

    /** Polls `path` every 5s until `data[flag]` turns false (max 30 min). */
    async function waitUntilDone(path, flag, onPoll = () => {}) {
        for (let i = 0; i < 360; i++) {
            await sleep(5000);
            const r = await request('GET', path);
            if (r.ok) onPoll(r.data);
            if (r.ok && !r.data[flag]) return r.data;
        }
        return null;
    }

    /**
     * Reads the live stage progress every 700ms while an update request is
     * open; returns stop(). Laravel sessions don't lock, so this runs beside it.
     */
    function watchProgress(run, onTick) {
        let stopped = false;
        (async () => {
            while (!stopped) {
                await sleep(700);
                if (stopped) break;
                const r = await request('GET', '/license/update/status');
                if (!stopped && r.ok && r.data.progress && r.data.progress.run === run) onTick(r.data.progress);
            }
        })();
        return () => {
            stopped = true;
        };
    }

    async function latestProgress(run) {
        const r = await request('GET', '/license/update/status');
        return (r.ok && r.data.progress && r.data.progress.run === run && r.data.progress) || null;
    }

    return {
        request,

        status: () => request('GET', '/license/status'),

        async saveLicense(license) {
            const r = await request('POST', '/license/save', { license });
            return { ok: r.ok, message: r.data.message || (r.ok ? 'License saved.' : 'Could not verify the license.') };
        },

        refreshLicense: () => request('POST', '/license/check'),

        /** Server-side health check of the provider before an update. */
        async preflight() {
            const r = await request('POST', '/license/update/preflight');
            return { ok: r.ok && !!r.data.ok, message: r.data.message || (r.ok ? '' : 'The provider server is not reachable.'), retryAt: r.data.retry_at || null };
        },

        async checkUpdate() {
            const { data } = await request('POST', '/license/update/check');
            return data;
        },

        /**
         * Applies updates one version step at a time until none is left.
         * onProgress receives { message, step, progress } while a step runs —
         * progress is the server's per-stage snapshot (see updateStages()) —
         * and { message, version, step, progress } after each step.
         * @returns {Promise<{ ok: boolean, message: string, version?: string, progress?: object }>}
         */
        async runUpdate(onProgress = () => {}) {
            let last = { ok: true, message: 'No update available.' };

            for (let step = 0; step < 20; step++) {
                const tick = (progress) => onProgress({ message: progressText(progress), step: step + 1, progress });
                onProgress({ message: 'Updating… do not close this page.', step: step + 1, progress: null });

                // Tags this step's snapshot, so a poll never shows the last step's.
                const run = Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
                const stop = watchProgress(run, tick);
                const r = await request('POST', '/license/update/run', { run });
                stop();
                const progress = await latestProgress(run);

                if (r.status === 409) {
                    return { ok: false, message: r.data.message || 'An update is already running.' };
                }

                // Provider unreachable (retry later) or update not included in the plan:
                // a definite answer, nothing is running in the background.
                if (r.status === 503 && r.data.retry_at !== undefined) {
                    return { ok: false, message: r.data.message, retryAt: r.data.retry_at, progress };
                }
                if (r.status === 402) {
                    return { ok: false, locked: true, message: r.data.message, progress };
                }

                // The request died (proxy/worker timeout) — the step may still finish.
                if (!r.ok && (r.status === 0 || r.status >= 500)) {
                    onProgress({ message: 'Waiting for the update to finish…', step: step + 1, progress });
                    const st = await waitUntilDone('/license/update/status', 'running', (d) => d.progress && d.progress.run === run && tick(d.progress));
                    return {
                        ok: !!st && st.last_update_status === 'success',
                        message: (st && st.last_update_message) || 'The update request was interrupted.',
                        version: st && st.version,
                        progress: st && st.progress,
                    };
                }

                if (!r.data.status) {
                    return { ok: false, message: r.data.message || 'Update failed.', progress };
                }

                last = { ok: true, message: r.data.message, version: r.data.version, step: step + 1, progress, more: !!r.data.update_available };
                onProgress(last);

                if (!r.data.update_available) break;
            }

            return last;
        },

        async toggleBackup(enabled) {
            const r = await request('POST', '/license/backup-toggle', { enabled });
            return { ok: r.ok, enabled: r.ok ? !!r.data.backup_enabled : !enabled };
        },

        /** @returns {Promise<{ ok: boolean, message: string }>} */
        async runBackup(onProgress = () => {}) {
            const r = await request('POST', '/license/backup/run');

            if (!r.ok) {
                return { ok: false, message: r.data.message || 'Backup failed.' };
            }

            if (r.data.running) {
                onProgress({ message: 'Backup running…' });
                const st = await waitUntilDone('/license/backup/status', 'running');
                return {
                    ok: !!st && st.last_backup_status === 'success',
                    message: (st && st.last_backup_message) || 'Backup finished.',
                };
            }

            return { ok: !!r.data.ok, message: r.data.message || 'Backup finished.' };
        },

        /** Update + backup history and the activity log merged, newest first. */
        async history(limit = 60) {
            const [u, b, a] = await Promise.all([
                request('GET', '/license/update/history'),
                request('GET', '/license/backup/history'),
                request('GET', '/license/activity'),
            ]);
            const ACTIVITY = { health: 'Server health', license: 'License check', 'update-check': 'Update check', update: 'Update', backup: 'Backup' };

            return [
                ...(u.data.history || []).map((h) => ({
                    key: 'u' + h.id,
                    at: h.created_at,
                    type: 'Update',
                    ok: h.ok,
                    message: (h.from_version || '?') + ' → ' + (h.to_version || '?') + ' · ' + (h.message || ''),
                })),
                ...(b.data.history || []).map((h) => ({
                    key: 'b' + h.id,
                    at: h.created_at,
                    type: 'Backup',
                    ok: h.ok,
                    message: h.message || '',
                })),
                ...((a.data && a.data.activity) || []).map((h) => ({
                    key: 'a' + h.id,
                    at: h.created_at,
                    type: ACTIVITY[h.type] || h.type,
                    ok: h.ok,
                    message: h.message || '',
                })),
            ]
                .sort((a, c) => (c.at || '').localeCompare(a.at || ''))
                .slice(0, limit);
        },

        /**
         * The provider's reachability is checked from the browser and reported to
         * the server, which gates its background tasks on it (see ServerHealth).
         */
        async reportHealth(providerUrl) {
            if (!providerUrl) return;

            let healthy = true;
            const ctrl = new AbortController();
            const timer = setTimeout(() => ctrl.abort(), 8000);
            try {
                await fetch(providerUrl, { mode: 'no-cors', cache: 'no-store', signal: ctrl.signal });
            } catch (e) {
                healthy = false;
            }
            clearTimeout(timer);

            await request('POST', '/license/health-report', { healthy });
        },
    };
}

/** The license fields shown on the License tab, in display order. */
export const LICENSE_FIELDS = [
    ['status', 'Status'],
    ['client_name', 'Client'],
    ['subscription_type', 'Plan'],
    ['paid_through', 'Paid through'],
    ['monthly_fee', 'Monthly fee'],
    ['fee_due', 'Subscription fee due'],
    ['other_due', 'Other dues'],
    ['due_amount', 'Total due'],
    ['next_due_date', 'Next due date'],
    ['last_verified_at', 'Last verified'],
];

export const TERMS = [
    'Each license key is bound to a single installation. Moving to a new server requires the provider to release the binding.',
    'This software periodically contacts the provider to verify the license, check for updates and, when enabled, upload database backups.',
    'Monthly plans include updates while the subscription is paid. Lifetime plans receive new versions only with an active monthly update subscription.',
    'An expired or invalid license pauses access to the application; it never deletes data.',
];
