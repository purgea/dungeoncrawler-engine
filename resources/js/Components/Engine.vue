<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, toRaw } from 'vue';
import * as pc from 'playcanvas';
import DungeonRenderer from './DungeonRenderer.vue';
import Loader from './Loader.vue';
import Minimap from './Minimap.vue';
import Player from './Player.vue';

const emit = defineEmits(['pickup-item', 'use-door', 'lock-change']);
const props = defineProps({
    dungeon: {
        type: Object,
        required: true,
    },
    decorationAssets: {
        type: Array,
        default: () => [],
    },
});
const viewport = ref(null);
const app = ref(null);
const playerComponent = ref(null);
const dungeonRenderer = ref(null);
const loaderComponent = ref(null);
const minimapGrid = ref([]);
const minimapPlayer = ref(null);
const minimapRevision = ref(0);
const exploredTiles = new Set();

let collisionGrid = [];
let currentFloor = 0;
let shinyObject = null;
let shinyObjectPosition = null;
let startDoorPosition = null;
let resizeObserver = null;

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

function rayIntersectsBox(rayOrigin, rayDirection, center, halfSize) {
    const min = new pc.Vec3(center.x - halfSize.x, center.y - halfSize.y, center.z - halfSize.z);
    const max = new pc.Vec3(center.x + halfSize.x, center.y + halfSize.y, center.z + halfSize.z);
    let tmin = -Infinity;
    let tmax = Infinity;

    for (const axis of ['x', 'y', 'z']) {
        if (Math.abs(rayDirection[axis]) < 1e-6) {
            if (rayOrigin[axis] < min[axis] || rayOrigin[axis] > max[axis]) {
                return false;
            }
            continue;
        }

        const invD = 1 / rayDirection[axis];
        let t1 = (min[axis] - rayOrigin[axis]) * invD;
        let t2 = (max[axis] - rayOrigin[axis]) * invD;
        if (t1 > t2) {
            [t1, t2] = [t2, t1];
        }
        tmin = Math.max(tmin, t1);
        tmax = Math.min(tmax, t2);
        if (tmax < tmin) {
            return false;
        }
    }

    return true;
}

function activateWinState() {
    if (document.pointerLockElement || pc.Mouse.isPointerLocked()) {
        app.value?.mouse?.disablePointerLock?.();
        document.exitPointerLock?.();
    }

    window.close();
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

    const rayStart = camera.camera.screenToWorld(event.x, event.y, camera.camera.nearClip);
    const rayEnd = camera.camera.screenToWorld(event.x, event.y, camera.camera.farClip);
    const rayDirection = new pc.Vec3().sub2(rayEnd, rayStart).normalize();

    if (shinyObjectPosition) {
        const shinyHit = rayIntersectsBox(rayStart, rayDirection, shinyObjectPosition, new pc.Vec3(0.8, 0.8, 0.8));
        if (shinyHit) {
            emit('pickup-item');
        }
    }

    if (startDoorPosition) {
        const doorHit = rayIntersectsBox(rayStart, rayDirection, startDoorPosition, new pc.Vec3(1.1, 1.6, 0.25));
        if (doorHit) {
            emit('use-door');
        }
    }
}

function onPlayerMove(movement) {
    currentFloor = movement?.floor ?? currentFloor;
    revealAroundPlayer();
}

function updateWorld() {
    const camera = playerComponent.value?.getCamera?.();
    const rotation = playerComponent.value?.getRotation?.();
    dungeonRenderer.value?.updateDecorations?.(rotation?.yaw ?? camera?.getEulerAngles?.().y ?? 0);

    if (shinyObject) {
        const t = performance.now() * 0.001;
        shinyObject.setLocalEulerAngles(t * 32, t * 58, t * 18);
        shinyObject.setLocalPosition(
            shinyObjectPosition.x,
            shinyObjectPosition.y + Math.sin(t * 3.2) * 0.14,
            shinyObjectPosition.z,
        );
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
    // Keep the dungeon readable, but let local light sources define the scene.
    app.value.scene.ambientLight = new pc.Color(0.15, 0.135, 0.115);

    loaderComponent.value.setMessage('Reading dungeon layout...');
    await nextFrame();

    const dungeon = dungeonRenderer.value;
    const layout = toRaw(props.dungeon);
    const { grid, door, spawn, relic, decorations = [] } = layout;
    const texture = dungeon.createDungeonTexture(app.value);
    const material = dungeon.createMaterial(texture);
    const doorTexture = dungeon.createDoorTexture(app.value);
    const doorMaterial = dungeon.createDoorMaterial(doorTexture);
    const recessMaterial = dungeon.createRecessMaterial(texture);
    const archMaterial = dungeon.createArchMaterial(texture);

    collisionGrid = grid;
    minimapGrid.value = grid;

    loaderComponent.value.setMessage('Building rooms and corridors...');
    await nextFrame();
    await dungeon.buildDungeon(app.value, grid, material, door, doorMaterial, recessMaterial, archMaterial, decorations);

    loaderComponent.value.setMessage('Placing player and relic...');
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
        },
    );
    revealAroundPlayer(true);
    startDoorPosition = { ...dungeon.worldPosition(door.x, door.y, door.floor ?? currentFloor), y: (door.floor ?? currentFloor) + dungeon.wallHeight / 2 };

    const shinyTile = relic;
    const shinyPosition = {
        ...dungeon.worldPosition(shinyTile.x, shinyTile.y, shinyTile.floor ?? 0),
        y: (shinyTile.floor ?? 0) + 1.05,
    };
    shinyPosition.x += Math.random() * 1.2 - 0.6;
    shinyPosition.z += Math.random() * 1.2 - 0.6;
    shinyObjectPosition = shinyPosition;
    shinyObject = new pc.Entity('shiny-object');
    const shinyMaterial = new pc.StandardMaterial();
    shinyMaterial.emissive.set(0.95, 0.86, 0.45);
    shinyMaterial.emissiveIntensity = 2.5;
    shinyMaterial.diffuse.set(0.22, 0.2, 0.08);
    shinyMaterial.specular.set(1, 1, 0.85);
    shinyMaterial.shininess = 96;
    shinyMaterial.update();
    shinyObject.addComponent('render', { type: 'sphere', material: shinyMaterial });
    shinyObject.setLocalPosition(shinyPosition.x, shinyPosition.y, shinyPosition.z);
    shinyObject.setLocalScale(0.8, 0.8, 0.8);
    app.value.root.addChild(shinyObject);

    app.value.on('update', updateWorld);

    loaderComponent.value.setMessage('Dungeon ready');
    await nextFrame();
    loaderComponent.value.hide();
}

function cleanup() {
    resizeObserver?.disconnect();
    shinyObject?.destroy?.();
    if (document.pointerLockElement || pc.Mouse.isPointerLocked()) {
        app.value?.mouse?.disablePointerLock?.();
        document.exitPointerLock?.();
    }
    playerComponent.value?.dispose?.();
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
            @lock-change="emit('lock-change', $event)"
            @move="onPlayerMove"
            @pointer-down="onPlayerPointerDown"
        />
        <DungeonRenderer ref="dungeonRenderer" :layout="dungeon" />
        <Minimap
            :grid="minimapGrid"
            :explored="exploredTiles"
            :player="minimapPlayer"
            :revision="minimapRevision"
        />
        <Loader ref="loaderComponent" />
    </div>
</template>
