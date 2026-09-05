<script setup>
import { ref, computed, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import Engine from '../Components/Engine.vue';
import Music from '../Components/Music.vue';
import { readCheckpoint, saveCheckpoint, clearCheckpoint, readSettings, campaignLevelUrl, nextChapterCheckpoint } from '../game/RunState.js';
const props = defineProps({ dungeon: { type: Object, required: true }, campaign: { type: Object, default: () => ({}) } });
const attempt = ref(0), active = ref(false), muted = ref(readSettings().muted), completed = ref(null);
const checkpoint = ref(readCheckpoint());
const levelUrl = computed(() => campaignLevelUrl(props.campaign));
const initialState = computed(() => checkpoint.value?.url === levelUrl.value ? checkpoint.value.player : {});
const totals = computed(() => checkpoint.value?.url === levelUrl.value ? checkpoint.value.totals : { kills: 0, elapsed: 0 });
const musicDefinitions = computed(() => props.dungeon.definitions?.music || []);
function onCheckpoint(state) { checkpoint.value = saveCheckpoint(levelUrl.value, state, totals.value); }
function onComplete(state) {
    if (completed.value) return;
    completed.value = state;
    if (props.campaign.nextLevelUrl) nextChapterCheckpoint(props.campaign.nextLevelUrl, state, totals.value);
    else clearCheckpoint();
}
function restart() { active.value = false; completed.value = null; attempt.value++; }
function nextLevel() {
    if (!completed.value || !props.campaign.nextLevelUrl) return;
    router.visit(props.campaign.nextLevelUrl, { preserveState: false });
}
function home() { active.value = false; router.visit('/'); }
onMounted(() => {
    // Replace ?new=1 and unseeded links so refresh always restores this run.
    if (levelUrl.value && window.location.pathname + window.location.search !== levelUrl.value) {
        router.replace({ url: levelUrl.value, preserveState: true, preserveScroll: true });
    }
});
</script>
<template>
    <main class="relative h-screen w-screen overflow-hidden bg-[#050a08] text-[#ece7d8]">
        <Engine :key="`${dungeon.seed}-${campaign.levelSlug}-${attempt}`" :dungeon="dungeon" :campaign="campaign" :initial-state="initialState" @checkpoint="onCheckpoint" @complete="onComplete" @restart="restart" @next="nextLevel" @home="home" @lock-change="active = $event" @mute-change="muted = $event" />
        <Music :key="campaign.stageSlug || campaign.stageName || campaign.levelSlug" :music-definitions="musicDefinitions" :active="active" :muted="muted" />
    </main>
</template>
