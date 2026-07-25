<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import * as pc from 'playcanvas';
import Camera from './Camera.vue';
import DungeonGenerator from './DungeonGenerator.vue';

const emit = defineEmits(['pickup-item', 'use-door', 'lock-change']);
const viewport = ref(null);
const app = ref(null);
const cameraComponent = ref(null);
const dungeonComponent = ref(null);

let collisionGrid = [];
let currentFloor = 0;
let shinyObject = null;
let shinyObjectPosition = null;
let startDoorPosition = null;
let resizeObserver = null;
let removeListeners = () => {};
let isLocked = false;
const keys = new Set();

function setLocked(nextLocked) {
    isLocked = nextLocked;
    emit('lock-change', nextLocked);
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
    removeListeners();

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

function installInput(canvas) {
    const lockMouse = () => {
        if (document.pointerLockElement || pc.Mouse.isPointerLocked()) {
            setLocked(true);
            return;
        }

        app.value.mouse.enablePointerLock(
            () => {
                setLocked(true);
            },
            () => {
                setLocked(false);
            },
        );
    };
    const onKeyDown = (event) => {
        if (['KeyW', 'KeyA', 'KeyS', 'KeyD', 'ShiftLeft'].includes(event.code)) {
            event.preventDefault();
        }

        keys.add(event.code);
    };
    const onKeyUp = (event) => keys.delete(event.code);
    const onClick = () => lockMouse();
    const onPointerLockChange = () => setLocked(Boolean(document.pointerLockElement || pc.Mouse.isPointerLocked()));
    const onPointerLockError = () => setLocked(false);
    const onMouseMove = (event) => {
        if (!document.pointerLockElement && !pc.Mouse.isPointerLocked()) {
            return;
        }

        const camera = cameraComponent.value;
        const rotation = camera.getRotation();
        camera.setRotation(rotation.yaw - event.dx * 0.12, Math.max(-82, Math.min(82, rotation.pitch - event.dy * 0.12)));
    };
    const onPointerDown = (event) => {
        const camera = cameraComponent.value?.getCamera?.();
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
    };

    app.value.mouse.disableContextMenu();
    app.value.mouse.on(pc.Mouse.EVENT_MOUSEMOVE, onMouseMove);
    app.value.mouse.on(pc.Mouse.EVENT_MOUSEDOWN, onPointerDown);
    window.addEventListener('keydown', onKeyDown);
    window.addEventListener('keyup', onKeyUp);
    document.body.addEventListener('click', onClick);
    viewport.value.addEventListener('click', onClick);
    canvas.addEventListener('click', onClick);
    document.addEventListener('pointerlockchange', onPointerLockChange);
    document.addEventListener('pointerlockerror', onPointerLockError);

    removeListeners = () => {
        app.value?.mouse?.off(pc.Mouse.EVENT_MOUSEMOVE, onMouseMove);
        app.value?.mouse?.off(pc.Mouse.EVENT_MOUSEDOWN, onPointerDown);
        window.removeEventListener('keydown', onKeyDown);
        window.removeEventListener('keyup', onKeyUp);
        document.body.removeEventListener('click', onClick);
        viewport.value?.removeEventListener('click', onClick);
        canvas.removeEventListener('click', onClick);
        document.removeEventListener('pointerlockchange', onPointerLockChange);
        document.removeEventListener('pointerlockerror', onPointerLockError);
    };
}

function updateMovement(dt) {
    const camera = cameraComponent.value;
    if (!camera) {
        return;
    }

    let inputX = 0;
    let inputZ = 0;

    if (keys.has('KeyW')) inputZ += 1;
    if (keys.has('KeyS')) inputZ -= 1;
    if (keys.has('KeyD')) inputX += 1;
    if (keys.has('KeyA')) inputX -= 1;

    const rotation = camera.getRotation();
    const yawRadians = rotation.yaw * pc.math.DEG_TO_RAD;
    const sinYaw = Math.sin(yawRadians);
    const cosYaw = Math.cos(yawRadians);
    if (inputX !== 0 || inputZ !== 0) {
        const length = Math.hypot(inputX, inputZ);
        const normalizedX = inputX / length;
        const normalizedZ = inputZ / length;
        const speed = ((keys.has('ShiftLeft') ? 8 : 4.6) * dt);
        const deltaX = (normalizedX * cosYaw - normalizedZ * sinYaw) * speed;
        const deltaZ = (-normalizedX * sinYaw - normalizedZ * cosYaw) * speed;

        camera.moveWithCollision(
            deltaX,
            deltaZ,
            collisionGrid,
            dungeonComponent.value.dungeonWidth,
            dungeonComponent.value.dungeonHeight,
            dungeonComponent.value.tileSize,
            currentFloor,
        );
    }

    if (shinyObject) {
        const t = performance.now() * 0.001;
        shinyObject.setLocalEulerAngles(t * 32, t * 58, t * 18);
        shinyObject.setLocalPosition(
            shinyObjectPosition.x,
            1.05 + Math.sin(t * 3.2) * 0.14,
            shinyObjectPosition.z,
        );
    }
}

function start() {
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
    app.value.scene.ambientLight = new pc.Color(0.085, 0.075, 0.065);

    const dungeon = dungeonComponent.value;
    const { grid, startRoom, door, spawn } = dungeon.generateDungeon();
    const texture = dungeon.createDungeonTexture(app.value);
    const material = dungeon.createMaterial(texture);
    const doorTexture = dungeon.createDoorTexture(app.value);
    const doorMaterial = dungeon.createDoorMaterial(doorTexture);
    const recessMaterial = dungeon.createRecessMaterial(texture);
    const archMaterial = dungeon.createArchMaterial(texture);

    collisionGrid = grid;
    dungeon.buildDungeon(app.value, grid, material, door, doorMaterial, recessMaterial, archMaterial);
    currentFloor = spawn.floor ?? 0;
    cameraComponent.value.setupCamera(app.value, { ...dungeon.worldPosition(spawn.x, spawn.y, currentFloor), y: 1.55 });
    startDoorPosition = { ...dungeon.worldPosition(door.x, door.y, door.floor ?? currentFloor), y: dungeon.wallHeight / 2 };

    const shinyTile = dungeon.findRandomFloorTile(collisionGrid, [
        { floor: startRoom.floor ?? 0, x: startRoom.x + Math.floor(startRoom.w / 2), y: startRoom.y + Math.floor(startRoom.h / 2) },
    ]);
    const shinyPosition = { ...dungeon.worldPosition(shinyTile.x, shinyTile.y, shinyTile.floor ?? 0), y: 1.05 };
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
    shinyObject.setLocalPosition(shinyPosition.x, 1.05, shinyPosition.z);
    shinyObject.setLocalScale(0.8, 0.8, 0.8);
    app.value.root.addChild(shinyObject);

    installInput(canvas);
    app.value.on('update', updateMovement);
}

function cleanup() {
    removeListeners();
    resizeObserver?.disconnect();
    shinyObject?.destroy?.();
    if (document.pointerLockElement || pc.Mouse.isPointerLocked()) {
        app.value?.mouse?.disablePointerLock?.();
        document.exitPointerLock?.();
    }
    app.value?.destroy?.();
}

onMounted(async () => {
    await nextTick();
    start();
});
onBeforeUnmount(cleanup);

defineExpose({
    cleanup,
});
</script>

<template>
    <div class="absolute inset-0 h-full w-full">
        <div ref="viewport" class="absolute inset-0 h-full w-full" />
        <Camera ref="cameraComponent" :player-radius="0.62" />
        <DungeonGenerator ref="dungeonComponent" />
    </div>
</template>
