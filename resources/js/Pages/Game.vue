<script setup>
import { ref } from 'vue';
import Engine from '../Components/Engine.vue';
import Music from '../Components/Music.vue';

defineProps({
    dungeon: {
        type: Object,
        required: true,
    },
    musicAssets: {
        type: Array,
        required: true,
    },
});

const hasShinyItem = ref(false);
const gameMessage = ref('Find the relic, then bring it back to the start door.');
const engineComponent = ref(null);
const musicComponent = ref(null);

function onPickupItem() {
    hasShinyItem.value = true;
    gameMessage.value = 'Relic acquired. Return to the start door.';
}

function onUseDoor() {
    if (!hasShinyItem.value) {
        gameMessage.value = 'The start door is sealed. You need the relic.';
        return;
    }

    gameMessage.value = 'Escape complete.';
    musicComponent.value?.stop?.();
    engineComponent.value?.cleanup?.();
    window.close();
}

</script>

<template>
    <main class="relative h-screen w-screen overflow-hidden bg-[#050604] text-[#ece7d8]">
        <Engine
            ref="engineComponent"
            :dungeon="dungeon"
            @pickup-item="onPickupItem"
            @use-door="onUseDoor"
        />
        <Music ref="musicComponent" :music-assets="musicAssets" />
        <div class="absolute left-4 top-4 flex w-[min(92vw,340px)] flex-col gap-3 border border-white/15 bg-black/60 px-3 py-3 shadow-[0_12px_40px_rgba(0,0,0,0.25)] backdrop-blur-md">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-[13px] font-bold uppercase tracking-wide">Dungeon Crawler Engine</div>
                    <div class="mt-1 text-xs text-[#bdb7a4]">{{ gameMessage }}</div>
                </div>
                <div class="rounded border border-white/15 px-2 py-1 text-[11px] uppercase tracking-[0.18em] text-[#d6cfbc]">
                    {{ hasShinyItem ? 'Relic held' : 'Empty hands' }}
                </div>
            </div>
            <div class="flex flex-wrap gap-2 text-xs text-[#bdb7a4]">
                <span class="border-l border-white/15 pl-2 first:border-l-0 first:pl-0">WASD</span>
                <span class="border-l border-white/15 pl-2">Mouse look</span>
                <span class="border-l border-white/15 pl-2">Shift sprint</span>
                <span class="border-l border-white/15 pl-2">Click relic</span>
                <span class="border-l border-white/15 pl-2">Click start door</span>
            </div>
        </div>
        <div class="pointer-events-none absolute left-1/2 top-1/2 h-4 w-4 -translate-x-1/2 -translate-y-1/2">
            <div class="absolute left-0 top-[7px] h-0.5 w-4 bg-[#ece7d8]/70" />
            <div class="absolute left-[7px] top-0 h-4 w-0.5 bg-[#ece7d8]/70" />
        </div>
    </main>
</template>
