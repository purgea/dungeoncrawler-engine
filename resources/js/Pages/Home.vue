<script setup>
import { router } from '@inertiajs/vue3';

defineProps({ world: { type: Object, default: null } });

function beginAdventure() {
    router.visit('/loading');
}
</script>

<template>
    <main class="flex min-h-screen items-center justify-center bg-[#0b0907] text-[#eadfbe]">
        <div class="w-full max-w-sm px-6 text-center">
            <p class="text-xs uppercase tracking-[0.3em] text-[#aa8d55]">Dungeon Crawler</p>
            <h1 class="mt-4 font-serif text-5xl text-[#f3e7c4]">{{ world?.name || 'The Ashen Realms' }}</h1>
            <p class="mt-3 text-sm text-[#8f846d]">{{ world?.description || 'A fallen kingdom awaits.' }}</p>
            <nav class="mt-12 space-y-3">
                <button type="button" class="w-full bg-[#b58c43] px-6 py-4 text-sm font-bold uppercase tracking-[0.2em] text-[#191108] transition hover:bg-[#d0aa5b]" @click="beginAdventure">New Game</button>
                <button type="button" class="w-full border border-[#806b43]/60 px-6 py-4 text-sm uppercase tracking-[0.2em] text-[#b9aa8e] transition hover:border-[#c7a85e] hover:text-[#eadfbe]" @click="beginAdventure">Continue</button>
                <a href="/loading" class="block w-full border border-[#806b43]/60 px-6 py-4 text-sm uppercase tracking-[0.2em] text-[#b9aa8e] transition hover:border-[#c7a85e] hover:text-[#eadfbe]">Loading</a>
            </nav>
            <p class="mt-12 text-[10px] uppercase tracking-[0.2em] text-[#6f634e]">WASD · Mouse · Left click</p>
        </div>
    </main>
</template>
