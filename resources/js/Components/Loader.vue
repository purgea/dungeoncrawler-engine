<script setup>
import { ref } from 'vue';

const isVisible = ref(true);
const message = ref('Preparing dungeon engine...');
const error = ref('');

function setMessage(nextMessage) {
    message.value = nextMessage;
    error.value = '';
    isVisible.value = true;
}

function hide() {
    isVisible.value = false;
}

function fail(nextError) {
    message.value = 'Dungeon generation failed';
    error.value = nextError?.message || nextError || 'The dungeon could not be generated.';
    isVisible.value = true;
}

defineExpose({
    setMessage,
    hide,
    fail,
});
</script>

<template>
    <div
        v-if="isVisible"
        class="absolute inset-0 z-50 flex items-center justify-center bg-[#050604] text-[#ece7d8]"
        role="status"
        aria-live="polite"
        @click.stop
    >
        <div class="flex w-[min(86vw,360px)] flex-col items-center text-center">
            <div class="relative h-16 w-16">
                <div class="absolute inset-0 rotate-45 border border-[#817965]" />
                <div
                    v-if="!error"
                    class="absolute inset-2 animate-spin border border-[#d8c98e] border-r-transparent"
                />
                <div
                    v-else
                    class="absolute inset-2 flex rotate-45 items-center justify-center border border-red-400/70 text-xl text-red-300"
                >
                    <span class="-rotate-45">!</span>
                </div>
            </div>
            <div class="mt-6 text-sm font-bold uppercase tracking-[0.24em]">
                {{ message }}
            </div>
            <div v-if="error" class="mt-3 text-xs leading-5 text-red-300">
                {{ error }}
            </div>
            <div v-else class="mt-3 text-xs uppercase tracking-[0.16em] text-[#928b78]">
                Carving the depths
            </div>
        </div>
    </div>
</template>
