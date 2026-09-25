<script setup>
// SUBandL subscription page (Vue 3 / Inertia). Logic lives in ../../subandl/subandl.js,
// shared with the Blade and React UIs — keep this file presentation-only.
import { onMounted, reactive, ref } from 'vue';
import { createSubandl, fmt, statusTone, LICENSE_FIELDS } from '../../subandl/subandl.js';
import '../../subandl/subandl.css';

const props = defineProps({
    currentVersion: String,
    tab: { type: String, default: 'license' },
    canUpdate: Boolean,
    canBackup: Boolean,
    licenseKey: String,
    backupEnabled: Boolean,
    backupIntervalHours: Number,
    providerUrl: String,
    base: { type: String, default: '' },
    urls: { type: Object, default: () => ({}) },
});

const api = createSubandl({ base: props.base });
const status = ref({});
const history = ref([]);
const flash = reactive({ message: '', tone: '' });
const busy = reactive({});
const license = ref(props.licenseKey || '');
const backupOn = ref(!!props.backupEnabled);
const version = ref(props.currentVersion);

const say = (message, tone = '') => Object.assign(flash, { message, tone });

async function withBusy(key, fn) {
    busy[key] = true;
    try { await fn(); } finally { busy[key] = false; }
}

async function loadStatus() {
    const r = await api.status();
    if (r.ok) status.value = r.data;
}

async function loadHistory() {
    if (props.tab !== 'license') history.value = await api.history();
}

const save = () => withBusy('save', async () => {
    const r = await api.saveLicense(license.value);
    say(r.message, r.ok ? 'ok' : 'bad');
    await loadStatus();
});

const refresh = () => withBusy('refresh', async () => {
    const r = await api.refreshLicense();
    if (r.ok) { status.value = r.data; say('License refreshed.', 'ok'); }
});

const checkUpdate = () => withBusy('check', async () => {
    const d = await api.checkUpdate();
    say(d.update_available
        ? `Version ${d.latest_version} is available.${d.changelog ? '\n' + d.changelog : ''}`
        : (d.message || 'You are on the latest version.'), d.update_available ? 'warn' : 'ok');
    loadStatus();
});

const runUpdate = () => withBusy('update', async () => {
    if (!confirm('Apply the update now? The application may be briefly unavailable.')) return;
    const r = await api.runUpdate((p) => {
        if (p.version) version.value = p.version;
        say(p.message, p.version ? 'ok' : 'warn');
    });
    if (r.version) version.value = r.version;
    say(r.message, r.ok ? 'ok' : 'bad');
    loadStatus(); loadHistory();
});

async function toggleBackup() {
    const r = await api.toggleBackup(backupOn.value);
    backupOn.value = r.enabled;
    say(r.ok ? `Automatic backup ${r.enabled ? 'enabled' : 'disabled'}.` : 'Could not change the setting.', r.ok ? 'ok' : 'bad');
}

const runBackup = () => withBusy('backup', async () => {
    const r = await api.runBackup((p) => say(p.message, 'warn'));
    say(r.message, r.ok ? 'ok' : 'bad');
    loadStatus(); loadHistory();
});

onMounted(() => {
    api.reportHealth(props.providerUrl);
    loadStatus();
    loadHistory();
});
</script>

<template>
    <div class="subandl">
        <h1>Subscription</h1>
        <div class="sb-muted">Version <strong>{{ version }}</strong></div>

        <nav class="sb-tabs">
            <a :href="urls.license" :class="{ 'sb-active': tab === 'license' }">License</a>
            <a v-if="canUpdate || canBackup" :href="urls.update" :class="{ 'sb-active': tab !== 'license' }">Update &amp; Backup</a>
        </nav>

        <div v-if="flash.message" class="sb-flash" :class="flash.tone && 'sb-' + flash.tone">{{ flash.message }}</div>

        <template v-if="tab === 'license'">
            <section class="sb-card">
                <h2>License</h2>
                <div class="sb-grid">
                    <div v-for="[key, label] in LICENSE_FIELDS" :key="key">
                        <div class="sb-label">{{ label }}</div>
                        <div class="sb-value">
                            <span v-if="key === 'status'" class="sb-badge" :class="'sb-' + statusTone(status.status)">
                                {{ fmt(status.status) }}{{ status.in_grace_period ? ' (grace)' : '' }}
                            </span>
                            <template v-else>{{ fmt(status[key]) }}</template>
                        </div>
                    </div>
                </div>
                <p v-if="status.message" class="sb-bad">{{ status.message }}</p>
            </section>

            <section class="sb-card">
                <h2>License key</h2>
                <form class="sb-row" @submit.prevent="save">
                    <input v-model="license" class="sb-input" autocomplete="off" placeholder="XXXX-XXXX-XXXX-XXXX">
                    <button class="sb-btn" type="submit" :disabled="busy.save">Save &amp; verify</button>
                    <button class="sb-btn sb-ghost" type="button" :disabled="busy.refresh" @click="refresh">Refresh</button>
                </form>
                <p class="sb-muted">By using this software you agree to the <a :href="urls.terms">terms</a>.</p>
            </section>
        </template>

        <template v-else>
            <section v-if="canUpdate" class="sb-card">
                <h2>Software update</h2>
                <p class="sb-muted">
                    Last check: {{ fmt(status.last_update_check_at) }} · Last result: {{ fmt(status.last_update_message) }}
                </p>
                <div class="sb-row">
                    <button class="sb-btn sb-ghost" :disabled="busy.check" @click="checkUpdate">Check for update</button>
                    <button class="sb-btn" :disabled="busy.update" @click="runUpdate">Update now</button>
                </div>
            </section>

            <section v-if="canBackup" class="sb-card">
                <h2>Backup</h2>
                <label class="sb-row" style="margin-bottom: 12px">
                    <input v-model="backupOn" type="checkbox" @change="toggleBackup">
                    Automatic cloud backup (every {{ backupIntervalHours }} hours)
                </label>
                <p class="sb-muted">
                    Last backup: {{ fmt(status.last_backup_at) }} {{ status.last_backup_status || '' }} · Next: {{ fmt(status.next_backup_at) }}
                </p>
                <button class="sb-btn" :disabled="busy.backup" @click="runBackup">Backup now</button>
            </section>

            <section class="sb-card">
                <h2>History</h2>
                <table class="sb-table">
                    <thead><tr><th>When</th><th>Type</th><th>Result</th><th>Details</th></tr></thead>
                    <tbody>
                        <tr v-if="!history.length"><td colspan="4" class="sb-muted">No history yet.</td></tr>
                        <tr v-for="h in history" :key="h.key">
                            <td>{{ fmt(h.at) }}</td>
                            <td>{{ h.type }}</td>
                            <td :class="h.ok ? 'sb-ok' : 'sb-bad'">{{ h.ok ? 'Success' : 'Failed' }}</td>
                            <td>{{ fmt(h.message) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </template>
    </div>
</template>
