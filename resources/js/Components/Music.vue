<script setup>
import { onBeforeUnmount, watch } from 'vue';
const props = defineProps({ musicDefinitions: { type: Array, default: () => [] }, active: Boolean, muted: Boolean });
let soundtrack = null;
function sync() {
    if (!props.active || props.muted) { soundtrack?.pause(); return; }
    if (!soundtrack) {
        const choices = props.musicDefinitions.filter((entry) => entry.path_url);
        const definition = choices[Math.floor(Math.random() * choices.length)];
        if (!definition) return;
        soundtrack = new Audio(definition.path_url);
        soundtrack.loop = true;
        soundtrack.preload = 'auto';
    }
    // Browsers may withhold autoplay; retry on the next deliberate resume.
    soundtrack.play().catch(() => {});
}
watch(() => [props.active, props.muted, props.musicDefinitions], sync, { deep: true, immediate: true });
onBeforeUnmount(() => { soundtrack?.pause(); if (soundtrack) soundtrack.src = ''; soundtrack = null; });
</script>
<template><div hidden /></template>
