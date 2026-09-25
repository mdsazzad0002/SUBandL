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
    async function waitUntilDone(path, flag) {
        for (let i = 0; i < 360; i++) {
            await sleep(5000);
            const r = await request('GET', path);
            if (r.ok && !r.data[flag]) return r.data;
        }
        return null;
    }

    return {
        request,

        status: () => request('GET', '/license/status'),

        async saveLicense(license) {
            const r = await request('POST', '/license/save', { license });
            return { ok: r.ok, message: r.data.message || (r.ok ? 'License saved.' : 'Could not verify the license.') };
        },

        refreshLicense: () => request('POST', '/license/check'),

        async checkUpdate() {
            const { data } = await request('POST', '/license/update/check');
            return data;
        },

        /**
         * Applies updates one version step at a time until none is left.
         * onProgress receives { message, version } after each step.
         * @returns {Promise<{ ok: boolean, message: string, version?: string }>}
         */
        async runUpdate(onProgress = () => {}) {
            let last = { ok: true, message: 'No update available.' };

            for (let step = 0; step < 20; step++) {
                onProgress({ message: 'Updating… do not close this page.' });
                const r = await request('POST', '/license/update/run');

                if (r.status === 409) {
                    return { ok: false, message: r.data.message || 'An update is already running.' };
                }

                // The request died (proxy/worker timeout) — the step may still finish.
                if (!r.ok && (r.status === 0 || r.status >= 500)) {
                    onProgress({ message: 'Waiting for the update to finish…' });
                    const st = await waitUntilDone('/license/update/status', 'running');
                    return {
                        ok: !!st && st.last_update_status === 'success',
                        message: (st && st.last_update_message) || 'The update request was interrupted.',
                        version: st && st.version,
                    };
                }

                if (!r.data.status) {
                    return { ok: false, message: r.data.message || 'Update failed.' };
                }

                last = { ok: true, message: r.data.message, version: r.data.version };
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

        /** Update + backup history merged, newest first. */
        async history(limit = 30) {
            const [u, b] = await Promise.all([
                request('GET', '/license/update/history'),
                request('GET', '/license/backup/history'),
            ]);

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
    ['expires_at', 'Expires'],
    ['update_support_expires_at', 'Update support until'],
    ['due_amount', 'Due amount'],
    ['last_verified_at', 'Last verified'],
];

export const TERMS = [
    'Each license key is bound to a single installation. Moving to a new server requires the provider to release the binding.',
    'This software periodically contacts the provider to verify the license, check for updates and, when enabled, upload database backups.',
    'Updates are applied only while the update/support period is active.',
    'An expired or invalid license pauses access to the application; it never deletes data.',
];
