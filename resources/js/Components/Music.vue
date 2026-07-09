<script setup>
let audioContext = null;
let musicNodes = [];
let musicTimer = null;

function noteToFrequency(semitonesFromA4) {
    return 440 * Math.pow(2, semitonesFromA4 / 12);
}

function scheduleTone({ frequency, startTime, duration, type = 'sine', gain = 0.04, detune = 0, filter = 1200 }) {
    const oscillator = audioContext.createOscillator();
    const band = audioContext.createBiquadFilter();
    const amp = audioContext.createGain();

    oscillator.type = type;
    oscillator.frequency.setValueAtTime(frequency, startTime);
    oscillator.detune.setValueAtTime(detune, startTime);
    band.type = 'lowpass';
    band.frequency.setValueAtTime(filter, startTime);
    amp.gain.setValueAtTime(0, startTime);
    amp.gain.linearRampToValueAtTime(gain, startTime + 0.04);
    amp.gain.exponentialRampToValueAtTime(0.001, startTime + duration);
    oscillator.connect(band).connect(amp).connect(audioContext.destination);
    oscillator.start(startTime);
    oscillator.stop(startTime + duration + 0.05);

    musicNodes.push(oscillator, band, amp);
}

async function start() {
    if (audioContext) {
        await audioContext.resume();
        return;
    }

    const AudioContextClass = window.AudioContext || window.webkitAudioContext;

    if (!AudioContextClass) {
        return;
    }

    audioContext = new AudioContextClass();
    const masterGain = audioContext.createGain();

    masterGain.gain.value = 0.28;
    masterGain.connect(audioContext.destination);
    musicNodes.push(masterGain);

    const now = audioContext.currentTime + 0.08;
    const motif = [0, -2, -3, -5, -7, -8, -10, -12];
    const bassLine = [-12, -12, -10, -12];
    const phraseLength = 3.2;

    const playPhrase = (offset = 0) => {
        const base = now + offset;
        const droneRoot = noteToFrequency(-17);
        const droneFifth = noteToFrequency(-10);

        scheduleTone({ frequency: droneRoot, startTime: base, duration: phraseLength + 0.6, type: 'sine', gain: 0.06, filter: 620 });
        scheduleTone({ frequency: droneFifth, startTime: base + 0.05, duration: phraseLength + 0.5, type: 'triangle', gain: 0.03, detune: -7, filter: 540 });

        bassLine.forEach((step, index) => {
            const startTime = base + index * 0.78;
            scheduleTone({
                frequency: noteToFrequency(step),
                startTime,
                duration: 0.6,
                type: 'triangle',
                gain: 0.045,
                detune: index % 2 === 0 ? -11 : 6,
                filter: 900,
            });
        });

        motif.forEach((step, index) => {
            const startTime = base + index * 0.35;
            scheduleTone({
                frequency: noteToFrequency(step + (index === 6 ? 1 : 0)),
                startTime,
                duration: 0.24,
                type: index % 3 === 0 ? 'sawtooth' : 'sine',
                gain: index === 6 ? 0.03 : 0.022,
                detune: index % 2 === 0 ? -4 : 4,
                filter: index === 6 ? 1600 : 1200,
            });
        });

        scheduleTone({ frequency: noteToFrequency(-3), startTime: base + 1.28, duration: 0.9, type: 'sine', gain: 0.018, detune: -13, filter: 800 });
        scheduleTone({ frequency: noteToFrequency(-5), startTime: base + 2.08, duration: 0.9, type: 'triangle', gain: 0.018, detune: 12, filter: 800 });
    };

    playPhrase(0);
    playPhrase(3.4);
    playPhrase(6.8);
    musicTimer = window.setInterval(() => {
        if (!audioContext) {
            return;
        }

        const cycleOffset = audioContext.currentTime + 0.12;
        playPhrase(cycleOffset - now);
    }, 6800);
    await audioContext.resume();
}

function stop() {
    if (musicTimer) {
        window.clearInterval(musicTimer);
        musicTimer = null;
    }

    musicNodes.forEach((node) => {
        try {
            node.stop?.();
            node.disconnect?.();
        } catch {
            node.disconnect?.();
        }
    });
    musicNodes = [];
    audioContext?.close();
    audioContext = null;
}

defineExpose({
    start,
    stop,
});
</script>

<template>
    <div hidden />
</template>
