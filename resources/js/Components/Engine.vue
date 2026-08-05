<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, toRaw } from 'vue';
import * as pc from 'playcanvas';
import DungeonRenderer from './DungeonRenderer.vue';
import Enemy from './Enemy.vue';
import Loader from './Loader.vue';
import Minimap from './Minimap.vue';
import Player from './Player.vue';
import Weapon from './Weapon.vue';
import { colorFromRgb, fogTypeFromName } from '../lighting';

const emit = defineEmits(['lock-change']);
const props = defineProps({
    dungeon: {
        type: Object,
        required: true,
    },
    decorationAssets: {
        type: Array,
        default: () => [],
    },
    weaponAssets: {
        type: Array,
        default: () => [],
    },
});
const viewport = ref(null);
const app = ref(null);
const playerComponent = ref(null);
const enemyComponent = ref(null);
const dungeonRenderer = ref(null);
const loaderComponent = ref(null);
const weaponComponent = ref(null);
const minimapGrid = ref([]);
const minimapPlayer = ref(null);
const minimapRevision = ref(0);
const playerHealth = ref(100);
const exploredTiles = new Set();

let collisionGrid = [];
let currentFloor = 0;
let resizeObserver = null;
let leftMouseHeld = false;

function findEnemySpawn(grid, spawn, tileSize, width, height) {
    const candidates = [];
    const sameFloorCandidates = [];
    for (let y = 1; y < height - 1; y += 1) {
        for (let x = 1; x < width - 1; x += 1) {
            const cell = grid[y]?.[x];
            if (!cell?.walkable || cell.floor !== spawn.floor || cell.type !== 'floor') {
                continue;
            }

            const distance = Math.hypot(x - spawn.x, y - spawn.y);
            sameFloorCandidates.push({ x, y, distance });
            if (distance >= 6 && distance <= 14) {
                candidates.push({ x, y, distance });
            }
        }
    }

    candidates.sort((a, b) => b.distance - a.distance);
    sameFloorCandidates.sort((a, b) => b.distance - a.distance);
    const selected = candidates[0] ?? sameFloorCandidates[0] ?? { x: spawn.x, y: spawn.y, distance: 0 };
    return {
        x: (selected.x - width / 2) * tileSize,
        y: grid[selected.y]?.[selected.x]?.elevation ?? spawn.floor,
        z: (selected.y - height / 2) * tileSize,
    };
}

function nextFrame() {
    return new Promise((resolve) => requestAnimationFrame(resolve));
}

function tileFromWorldPosition(position) {
    const dungeon = dungeonRenderer.value;
    const x = Math.floor(position.x / dungeon.tileSize + dungeon.dungeonWidth / 2 + 0.5);
    const y = Math.floor(position.z / dungeon.tileSize + dungeon.dungeonHeight / 2 + 0.5);
    return { x, y, floor: collisionGrid[y]?.[x]?.floor ?? currentFloor };
}

function revealAroundPlayer(force = false) {
    const camera = playerComponent.value?.getCamera?.();
    if (!camera || !collisionGrid.length) {
        return;
    }

    const tile = tileFromWorldPosition(camera.getLocalPosition());
    const previous = minimapPlayer.value;
    if (!force && previous?.x === tile.x && previous?.y === tile.y && previous?.floor === tile.floor) {
        return;
    }

    minimapPlayer.value = tile;
    let changed = false;
    const revealRadius = 2;
    for (let y = tile.y - revealRadius; y <= tile.y + revealRadius; y += 1) {
        for (let x = tile.x - revealRadius; x <= tile.x + revealRadius; x += 1) {
            const cell = collisionGrid[y]?.[x];
            if (Math.hypot(x - tile.x, y - tile.y) > revealRadius + 0.25 || !cell?.walkable || cell.floor !== tile.floor) {
                continue;
            }

            const key = `${tile.floor}:${x}:${y}`;
            if (!exploredTiles.has(key)) {
                exploredTiles.add(key);
                changed = true;
            }
        }
    }

    if (changed || force) {
        minimapRevision.value += 1;
    }
}

function resizeCanvasToViewport() {
    if (!app.value || !viewport.value) {
        return;
    }

    app.value.resizeCanvas(Math.max(1, viewport.value.clientWidth), Math.max(1, viewport.value.clientHeight));
}

function onPlayerPointerDown(event) {
    const camera = playerComponent.value?.getCamera?.();
    if (event.button !== pc.MOUSEBUTTON_LEFT || !camera) {
        return;
    }

    leftMouseHeld = true;
    weaponComponent.value?.fire?.(camera);
}

function onPlayerPointerUp(event) {
    if (event.button === pc.MOUSEBUTTON_LEFT) {
        leftMouseHeld = false;
    }
}

function onPlayerLockChange(locked) {
    if (!locked) {
        leftMouseHeld = false;
    }

    emit('lock-change', locked);
}

function onPlayerMove(movement) {
    currentFloor = movement?.floor ?? currentFloor;
    revealAroundPlayer();
}

function updateWorld(dt) {
    const camera = playerComponent.value?.getCamera?.();
    const rotation = playerComponent.value?.getRotation?.();
    dungeonRenderer.value?.updateDecorations?.(rotation?.yaw ?? camera?.getEulerAngles?.().y ?? 0);
    dungeonRenderer.value?.updateLighting?.(dt);
    weaponComponent.value?.setMoving?.(playerComponent.value?.isMoving?.() ?? false);

    if (leftMouseHeld && camera) {
        weaponComponent.value?.fire?.(camera);
    }

}

async function start() {
    loaderComponent.value.setMessage('Preparing renderer...');
    const canvas = document.createElement('canvas');
    canvas.className = 'dungeon-canvas';
    canvas.style.display = 'block';
    canvas.style.width = '100%';
    canvas.style.height = '100%';
    viewport.value.appendChild(canvas);

    app.value = new pc.Application(canvas, {
        keyboard: new pc.Keyboard(window),
        mouse: new pc.Mouse(canvas),
    });
    app.value.setCanvasFillMode(pc.FILLMODE_NONE);
    app.value.setCanvasResolution(pc.RESOLUTION_AUTO);
    app.value.start();
    resizeCanvasToViewport();

    resizeObserver = new ResizeObserver(resizeCanvasToViewport);
    resizeObserver.observe(viewport.value);
    const layout = toRaw(props.dungeon);
    const lighting = layout.lighting;
    // Keep the global fill low enough for local lights to matter, while still
    // lifting unlit surfaces above pure black so the player can read the room.
    app.value.scene.ambientLight = colorFromRgb(lighting.scene.ambient);
    app.value.scene.exposure = lighting.scene.exposure;
    app.value.scene.fog.type = fogTypeFromName(lighting.scene.fog.type);
    app.value.scene.fog.color = colorFromRgb(lighting.scene.fog.color);
    app.value.scene.fog.density = lighting.scene.fog.density;
    app.value.scene.fog.start = lighting.scene.fog.start;
    app.value.scene.fog.end = lighting.scene.fog.end;

    loaderComponent.value.setMessage('Reading dungeon layout...');
    await nextFrame();

    const dungeon = dungeonRenderer.value;
    const { grid, spawn, decorations = [] } = layout;
    const texture = dungeon.createDungeonTexture(app.value);
    const material = dungeon.createMaterial(texture);

    collisionGrid = grid;
    minimapGrid.value = grid;

    loaderComponent.value.setMessage('Building rooms and corridors...');
    await nextFrame();
    await dungeon.buildDungeon(app.value, grid, material, decorations);

    loaderComponent.value.setMessage('Placing player...');
    await nextFrame();
    currentFloor = spawn.floor ?? 0;
    playerComponent.value.setupPlayer(
        app.value,
        canvas,
        { ...dungeon.worldPosition(spawn.x, spawn.y, currentFloor), y: currentFloor + 1.55 },
        {
            grid: collisionGrid,
            width: dungeon.dungeonWidth,
            height: dungeon.dungeonHeight,
            tileSize: dungeon.tileSize,
            lighting,
        },
    );
    const enemySpawn = findEnemySpawn(grid, spawn, dungeon.tileSize, dungeon.dungeonWidth, dungeon.dungeonHeight);
    enemyComponent.value.setupEnemy(
        app.value,
        playerComponent.value,
        enemySpawn,
        {
            grid: collisionGrid,
            width: dungeon.dungeonWidth,
            height: dungeon.dungeonHeight,
            tileSize: dungeon.tileSize,
            lighting,
        },
    );
    await weaponComponent.value.setupWeapon(
        app.value,
        playerComponent.value.getCamera(),
        toRaw(props.weaponAssets),
        lighting,
    );
    weaponComponent.value.setCollisionGrid(
        collisionGrid,
        dungeon.dungeonWidth,
        dungeon.dungeonHeight,
        dungeon.tileSize,
    );
    revealAroundPlayer(true);

    app.value.on('update', updateWorld);

    loaderComponent.value.setMessage('Dungeon ready');
    await nextFrame();
    loaderComponent.value.hide();
}

function cleanup() {
    leftMouseHeld = false;
    resizeObserver?.disconnect();
    if (document.pointerLockElement || pc.Mouse.isPointerLocked()) {
        app.value?.mouse?.disablePointerLock?.();
        document.exitPointerLock?.();
    }
    playerComponent.value?.dispose?.();
    enemyComponent.value?.cleanup?.();
    weaponComponent.value?.cleanup?.();
    app.value?.off('update', updateWorld);
    app.value?.destroy?.();
}

onMounted(async () => {
    await nextTick();
    await nextFrame();

    try {
        await start();
    } catch (error) {
        loaderComponent.value?.fail(error);
        console.error('Unable to initialize dungeon.', error);
    }
});
onBeforeUnmount(cleanup);

defineExpose({
    cleanup,
});
</script>

<template>
    <div class="absolute inset-0 h-full w-full">
        <div ref="viewport" class="absolute inset-0 h-full w-full" />
        <Player
            ref="playerComponent"
            :player-radius="0.62"
            @lock-change="onPlayerLockChange"
            @move="onPlayerMove"
            @pointer-down="onPlayerPointerDown"
            @pointer-up="onPlayerPointerUp"
            @health-change="playerHealth = $event"
        />
        <Enemy ref="enemyComponent" />
        <Weapon ref="weaponComponent" />
        <DungeonRenderer ref="dungeonRenderer" :layout="dungeon" />
        <Minimap
            :grid="minimapGrid"
            :explored="exploredTiles"
            :player="minimapPlayer"
            :revision="minimapRevision"
        />
        <Loader ref="loaderComponent" />
        <div class="pointer-events-none absolute left-5 top-5 rounded bg-black/55 px-3 py-2 text-xs tracking-widest text-[#ece7d8]">
            HP {{ playerHealth }}
        </div>
    </div>
</template>
