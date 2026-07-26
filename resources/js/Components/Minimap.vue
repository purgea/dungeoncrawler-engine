<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    grid: {
        type: Array,
        default: () => [],
    },
    explored: {
        type: Set,
        default: () => new Set(),
    },
    player: {
        type: Object,
        default: null,
    },
    revision: {
        type: Number,
        default: 0,
    },
});

const canvas = ref(null);
let resizeObserver = null;

function draw() {
    const element = canvas.value;
    const floorGrid = props.grid;
    if (!element || !floorGrid?.length) {
        return;
    }

    const width = floorGrid[0].length;
    const height = floorGrid.length;
    const bounds = element.getBoundingClientRect();
    const pixelRatio = Math.min(window.devicePixelRatio || 1, 2);
    const size = Math.max(1, Math.floor(bounds.width * pixelRatio));

    if (element.width !== size || element.height !== size) {
        element.width = size;
        element.height = size;
    }

    const ctx = element.getContext('2d');
    const cellWidth = size / width;
    const cellHeight = size / height;
    const floor = props.player?.floor ?? 0;

    ctx.clearRect(0, 0, size, size);
    ctx.fillStyle = '#070906';
    ctx.fillRect(0, 0, size, size);

    ctx.strokeStyle = 'rgba(236, 231, 216, 0.055)';
    ctx.lineWidth = Math.max(0.5, pixelRatio * 0.35);
    ctx.beginPath();
    for (let x = 0; x <= width; x += 1) {
        ctx.moveTo(x * cellWidth, 0);
        ctx.lineTo(x * cellWidth, size);
    }
    for (let y = 0; y <= height; y += 1) {
        ctx.moveTo(0, y * cellHeight);
        ctx.lineTo(size, y * cellHeight);
    }
    ctx.stroke();

    for (const key of props.explored) {
        const [tileFloor, x, y] = key.split(':').map(Number);
        const cell = floorGrid[y]?.[x];
        if (tileFloor !== floor || !cell?.walkable || cell.floor !== floor) {
            continue;
        }

        ctx.fillStyle = cell.type === 'vertical-corridor' ? '#91845b' : '#4f574b';
        ctx.fillRect(
            x * cellWidth + 0.35 * pixelRatio,
            y * cellHeight + 0.35 * pixelRatio,
            Math.max(1, cellWidth - 0.7 * pixelRatio),
            Math.max(1, cellHeight - 0.7 * pixelRatio),
        );
    }

    if (props.player) {
        const centerX = (props.player.x + 0.5) * cellWidth;
        const centerY = (props.player.y + 0.5) * cellHeight;
        ctx.fillStyle = '#f2d46b';
        ctx.shadowColor = '#ffe792';
        ctx.shadowBlur = 4 * pixelRatio;
        ctx.beginPath();
        ctx.arc(centerX, centerY, Math.max(2.2 * pixelRatio, cellWidth * 0.72), 0, Math.PI * 2);
        ctx.fill();
        ctx.shadowBlur = 0;
    }
}

watch(() => [props.grid, props.player, props.revision], draw, { deep: false });

onMounted(() => {
    resizeObserver = new ResizeObserver(draw);
    resizeObserver.observe(canvas.value);
    draw();
});

onBeforeUnmount(() => resizeObserver?.disconnect());
</script>

<template>
    <aside
        class="pointer-events-none absolute right-4 top-4 z-10 w-[min(35vw,220px)] border border-white/20 bg-black/70 p-2 shadow-[0_12px_40px_rgba(0,0,0,0.35)] backdrop-blur-md"
        aria-label="Dungeon minimap"
    >
        <canvas ref="canvas" class="block aspect-square w-full border border-white/10" />
    </aside>
</template>
