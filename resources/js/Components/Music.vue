<script setup>
import { onBeforeUnmount, onMounted } from 'vue';

const props = defineProps({
    musicAssets: {
        type: Array,
        required: true,
    },
});

let soundtrack = null;
const soundtrackUrl = props.musicAssets[0]?.path_url;

async function start() {
    if (!soundtrackUrl) {
        console.warn('No music assets are available.');
        return;
    }

    console.info('Loading dungeon soundtrack.', soundtrackUrl);

    if (!soundtrack) {
        soundtrack = new Audio(soundtrackUrl);
        soundtrack.loop = true;
        soundtrack.preload = 'auto';
        soundtrack.volume = 1;
        soundtrack.addEventListener('canplaythrough', () => {
            console.info('Dungeon soundtrack is ready to play.', soundtrackUrl);
        }, { once: true });
        soundtrack.addEventListener('error', () => {
            console.error('Dungeon soundtrack failed to load.', {
                source: soundtrack?.currentSrc || soundtrackUrl,
                networkState: soundtrack?.networkState,
                readyState: soundtrack?.readyState,
                mediaError: soundtrack?.error,
            });
        });
    }

    try {
        console.info('Starting dungeon soundtrack.', soundtrackUrl);
        await soundtrack.play();
        console.info('Dungeon soundtrack is playing.');
    } catch (error) {
        console.error('Unable to play dungeon soundtrack.', error);
        throw error;
    }
}

function stop() {
    if (!soundtrack) {
        return;
    }

    soundtrack.pause();
    soundtrack.currentTime = 0;
}

onMounted(start);

onBeforeUnmount(() => {
    stop();
    soundtrack = null;
});

defineExpose({
    start,
    stop,
});
</script>

<template>
    <div hidden />
</template>
