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
    decorationAssets: {
        type: Array,
        required: true,
    },
    weaponAssets: {
        type: Array,
        required: true,
    },
});

const engineComponent = ref(null);
const musicComponent = ref(null);

</script>

<template>
    <main class="relative h-screen w-screen overflow-hidden bg-[#050604] text-[#ece7d8]">
        <Engine
            ref="engineComponent"
            :dungeon="dungeon"
            :decoration-assets="decorationAssets"
            :weapon-assets="weaponAssets"
        />
        <Music ref="musicComponent" :music-assets="musicAssets" />
        <div class="pointer-events-none absolute left-1/2 top-1/2 h-4 w-4 -translate-x-1/2 -translate-y-1/2">
            <div class="absolute left-0 top-[7px] h-0.5 w-4 bg-[#ece7d8]/70" />
            <div class="absolute left-[7px] top-0 h-4 w-0.5 bg-[#ece7d8]/70" />
        </div>
    </main>
</template>
