<script setup>
import { onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import Loader from '../Components/Loader.vue';

onMounted(() => {
    router.get('/game/1-1', {}, {
        replace: true,
    });
});
</script>

<template>
    <main class="relative h-screen w-screen overflow-hidden bg-[#050604]">
        <Loader />
    </main>
</template>
