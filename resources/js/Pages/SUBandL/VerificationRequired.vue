<script setup>
import '../../subandl/subandl.css';

defineProps({
    status: String,
    message: String,
    urls: { type: Object, default: () => ({}) },
});
</script>

<template>
    <div class="subandl">
        <section class="sb-card">
            <h1>License verification required</h1>
            <p class="sb-muted">This installation could not be verified, so access is paused. No data has been changed.</p>
            <p>Status: <span class="sb-badge sb-bad">{{ status }}</span></p>
            <p v-if="message" class="sb-bad">{{ message }}</p>
            <p>Enter or refresh your license key on the <a :href="urls.license">subscription page</a>, or contact your provider.</p>
        </section>
    </div>
</template>
