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
        class="absolute inset-0 z-50 flex items-center justify-center bg-[#090806] text-[#e6d7a4]"
        role="status"
        aria-live="polite"
        @click.stop
    >
        <div class="flex w-[min(86vw,360px)] flex-col items-center text-center">
            <div
                v-if="!error"
                class="h-[30px] w-[30px] animate-spin border-4 border-[#594a31] border-r-[#e0bd68] border-t-[#e0bd68] [animation-timing-function:steps(8,end)]"
                aria-hidden="true"
            >
                <span />
            </div>
            <div v-else class="flex h-[46px] w-[46px] items-center justify-center border-2 border-[#b65d43] text-[28px] font-bold leading-[42px] text-[#d98261]" aria-hidden="true">!</div>
            <div class="mt-5 text-sm font-bold uppercase tracking-[0.16em]">
                {{ message }}
            </div>
            <div v-if="error" class="mt-3 text-xs leading-5 text-red-300">
                {{ error }}
            </div>
        </div>
    </div>
</template>
