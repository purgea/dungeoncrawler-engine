<script setup>
import { onBeforeUnmount, watch } from 'vue';
const props = defineProps({ musicDefinitions: { type: Array, default: () => [] }, active: Boolean, muted: Boolean });
let soundtrack = null;
function sync() {
    if (!props.active || props.muted) { soundtrack?.pause(); return; }
    if (!soundtrack) {
        const definition = props.musicDefinitions.find((entry) => entry.path_url);
        if (!definition) return;
        soundtrack = new Audio(definition.path_url);
        soundtrack.loop = true;
        soundtrack.preload = 'none';
        soundtrack.volume = 0.22;
    }
    // Browsers may withhold autoplay; retry on the next deliberate resume.
    soundtrack.play().catch(() => {});
}
watch(() => [props.active, props.muted], sync, { immediate: true });
onBeforeUnmount(() => { soundtrack?.pause(); if (soundtrack) soundtrack.src = ''; soundtrack = null; });
</script>
<template><div hidden /></template>
