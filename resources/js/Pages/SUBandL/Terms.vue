<script setup>
import { TERMS } from '../../subandl/subandl.js';
import '../../subandl/subandl.css';

defineProps({ urls: { type: Object, default: () => ({}) } });
</script>

<template>
    <div class="subandl">
        <section class="sb-card">
            <h1>License terms</h1>
            <ol><li v-for="term in TERMS" :key="term">{{ term }}</li></ol>
            <p><a :href="urls.license">Back to subscription</a></p>
        </section>
    </div>
</template>
