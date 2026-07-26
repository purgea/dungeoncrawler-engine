<script setup>
import { onBeforeUnmount, onMounted } from 'vue';

const dungeonSoundtrackUrl = `/dungeon.wav`;
let soundtrack = null;

async function start() {
    console.info('Loading dungeon soundtrack.', dungeonSoundtrackUrl);

    if (!soundtrack) {
        soundtrack = new Audio(dungeonSoundtrackUrl);
        soundtrack.loop = true;
        soundtrack.preload = 'auto';
        soundtrack.volume = 1;
        soundtrack.addEventListener('canplaythrough', () => {
            console.info('Dungeon soundtrack is ready to play.', dungeonSoundtrackUrl);
        }, { once: true });
        soundtrack.addEventListener('error', () => {
            console.error('Dungeon soundtrack failed to load.', {
                source: soundtrack?.currentSrc || dungeonSoundtrackUrl,
                networkState: soundtrack?.networkState,
                readyState: soundtrack?.readyState,
                mediaError: soundtrack?.error,
            });
        });
    }

    try {
        console.info('Starting dungeon soundtrack.', dungeonSoundtrackUrl);
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
