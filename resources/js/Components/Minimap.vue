<script setup>
import { onBeforeUnmount, onMounted, ref, watch, nextTick } from 'vue';
const props = defineProps({
    grid: { type: Array, default: () => [] }, explored: { type: Set, default: () => new Set() },
    player: { type: Object, default: null }, revision: { type: Number, default: 0 },
    markers: { type: Array, default: () => [] }, exit: Object, expanded: Boolean,
    path: { type: Array, default: () => [] },
});
const canvas = ref(null);
let resizeObserver = null;
function draw() {
    const element = canvas.value;
    if (!element || !props.grid.length) return;
    const width = props.grid[0].length, height = props.grid.length;
    const pixelRatio = Math.min(window.devicePixelRatio || 1, 2);
    const size = Math.max(1, Math.floor(element.getBoundingClientRect().width * pixelRatio));
    if (element.width !== size || element.height !== size) element.width = element.height = size;
    const ctx = element.getContext('2d');
    const floor = props.player?.floor ?? 0;
    const range = props.expanded ? Math.max(width, height) + 2 : 23;
    const scale = size / range;
    const offsetX = props.expanded ? (range - width) / 2 : range / 2 - (props.player?.x ?? width / 2) - 0.5;
    const offsetY = props.expanded ? (range - height) / 2 : range / 2 - (props.player?.y ?? height / 2) - 0.5;
    const center = p => [(p.x + offsetX + 0.5) * scale, (p.y + offsetY + 0.5) * scale];
    ctx.clearRect(0, 0, size, size);
    ctx.fillStyle = '#091310ed';
    ctx.fillRect(0, 0, size, size);
    for (const key of props.explored) {
        const [tileFloor, x, y] = key.split(':').map(Number), cell = props.grid[y]?.[x];
        if (!cell?.walkable || (!props.expanded && tileFloor !== floor)) continue;
        ctx.fillStyle = tileFloor !== floor ? '#253332' : cell.type === 'vertical-corridor' ? '#a38b52' : '#556759';
        ctx.fillRect((x + offsetX) * scale + .3, (y + offsetY) * scale + .3, Math.max(1, scale - .6), Math.max(1, scale - .6));
    }
    // The sigils reveal a faint thread through unexplored halls; this also guides floor changes.
    if (props.path.length) {
        ctx.beginPath();
        ctx.strokeStyle = props.expanded ? '#87b79788' : '#87b79766';
        ctx.lineWidth = Math.max(1, pixelRatio);
        ctx.setLineDash([2 * pixelRatio, 3 * pixelRatio]);
        props.path.forEach((p, i) => { const [x, y] = center(p); if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y); });
        ctx.stroke();
        ctx.setLineDash([]);
    }
    const mark = (item, color, always = false) => {
        if (!props.expanded && item.floor !== floor) return;
        if (!always && !props.explored.has(`${item.floor}:${item.x}:${item.y}`)) return;
        const [x, y] = center(item);
        ctx.fillStyle = color;
        ctx.save(); ctx.translate(x, y); ctx.rotate(Math.PI / 4);
        const radius = Math.max(1.8 * pixelRatio, scale * .34);
        ctx.fillRect(-radius, -radius, radius * 2, radius * 2);
        ctx.restore();
    };
    for (const item of props.markers) mark(item, item.color ? `rgb(${item.color.map(value => Math.round(value * 255)).join(' ')})` : '#ccd4b2', item.type === 'sigil');
    if (props.exit) mark(props.exit, '#bb94d6', true);
    if (props.player) {
        const [x, y] = center(props.player);
        ctx.save(); ctx.translate(x, y); ctx.rotate(-(props.player.yaw || 0) * Math.PI / 180);
        ctx.fillStyle = '#f2d798'; ctx.shadowColor = '#f6d59a'; ctx.shadowBlur = 4 * pixelRatio;
        ctx.beginPath(); ctx.moveTo(0, -5 * pixelRatio); ctx.lineTo(-3.5 * pixelRatio, 3.8 * pixelRatio); ctx.lineTo(0, 2 * pixelRatio); ctx.lineTo(3.5 * pixelRatio, 3.8 * pixelRatio); ctx.closePath(); ctx.fill(); ctx.restore();
    }
}
watch(() => [props.grid, props.player, props.revision, props.markers, props.path, props.expanded], async () => { await nextTick(); draw(); });
onMounted(() => { resizeObserver = new ResizeObserver(draw); resizeObserver.observe(canvas.value); draw(); });
onBeforeUnmount(() => resizeObserver?.disconnect());
</script>
<template>
    <aside class="dungeon-map" :class="{ expanded }" aria-label="Dungeon map">
        <div class="map-label"><span>{{ expanded ? 'CARTOGRAPHY' : 'THE DEEP' }}</span><span>{{ player?.floor === 0 ? 'GROUND' : `${player?.floor > 0 ? '+' : ''}${player?.floor ?? 0}m` }}</span></div>
        <canvas ref="canvas" />
        <div v-if="expanded" class="map-legend"><span class="legend-player">▲ You</span><span class="legend-sigil">◆ Sigil</span><span class="legend-gate">◆ Gate</span><span>··· Route</span><span>Tab to close</span></div>
    </aside>
</template>
<style scoped>
.dungeon-map{pointer-events:none;position:absolute;right:28px;top:28px;z-index:15;width:176px;padding:7px;border:1px solid #b6995e45;background:#07100ddd;box-shadow:0 8px 25px #0005;backdrop-filter:blur(6px)}.dungeon-map canvas{display:block;aspect-ratio:1;width:100%;border:1px solid #74866322}.map-label{display:flex;justify-content:space-between;font:8px system-ui;letter-spacing:.12em;color:#b0a078;padding:1px 1px 7px}.dungeon-map.expanded{width:min(68vh,65vw);right:50%;top:45%;transform:translate(50%,-50%);padding:14px;background:#06100bf5;border-color:#aa9b6977;box-shadow:0 15px 90px #000b}.map-legend{display:flex;flex-wrap:wrap;justify-content:center;gap:14px;margin-top:10px;font:9px system-ui;color:#829681}.legend-player{color:#f2d798}.legend-sigil{color:#bce6ae}.legend-gate{color:#bb94d6}@media(max-width:800px){.dungeon-map{right:16px;top:16px;width:132px}.dungeon-map.expanded{width:min(65vh,82vw);right:50%;top:43%}}
</style>
