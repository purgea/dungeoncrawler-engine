<script setup>
import { onBeforeUnmount } from 'vue';
import * as pc from 'playcanvas';
import {
    getRampCells,
    isWalkableWorldPosition,
    surfaceElevation,
    tileAtWorldPosition,
} from '../ai/GridCollision';
import { colorFromRgb, falloffModeFromName, toneMappingFromName } from '../lighting';

const emit = defineEmits(['lock-change', 'move', 'pointer-down', 'pointer-up', 'health-change']);

const props = defineProps({
    playerRadius: {
        type: Number,
        required: true,
    },
});

let camera = null;
let playerLight = null;
let app = null;
let lighting = null;
let yaw = 0;
let pitch = 0;
let collisionGrid = [];
let dungeonWidth = 0;
let dungeonHeight = 0;
let tileSize = 0;
let rampCells = [];
let health = 100;
let removeListeners = () => {};
const keys = new Set();

function setupPlayer(appInstance, canvas, spawnPoint, dungeon) {
    app = appInstance;
    collisionGrid = dungeon.grid;
    dungeonWidth = dungeon.width;
    dungeonHeight = dungeon.height;
    tileSize = dungeon.tileSize;
    lighting = dungeon.lighting;
    rampCells = getRampCells(collisionGrid);
    health = 100;
    camera = new pc.Entity('player-camera');
    const cameraLighting = lighting.camera;
    camera.addComponent('camera', {
        clearColor: colorFromRgb(cameraLighting.clear_color),
        fov: 72,
        nearClip: 0.05,
        farClip: 500,
        toneMapping: toneMappingFromName(cameraLighting.tone_mapping),
    });
    const playerLightConfig = lighting.player.light;
    playerLight = new pc.Entity('player-visibility-light');
    playerLight.addComponent('light', {
        type: 'omni',
        color: colorFromRgb(playerLightConfig.color),
        intensity: playerLightConfig.intensity,
        range: Math.max(tileSize * playerLightConfig.range_tiles, 1),
        falloffMode: falloffModeFromName(playerLightConfig.falloff),
        castShadows: playerLightConfig.cast_shadows,
    });
    playerLight.setLocalPosition(...playerLightConfig.position);
    camera.addChild(playerLight);
    camera.setLocalPosition(spawnPoint.x, spawnPoint.y, spawnPoint.z);
    appInstance.root.addChild(camera);
    installInput(canvas);
    appInstance.on('update', updateMovement);

    return { camera, start: spawnPoint };
}

function takeDamage(amount) {
    health = Math.max(0, health - Math.max(0, Number(amount) || 0));
    emit('health-change', health);
    return health;
}

function getHealth() {
    return health;
}

function setRotation(nextYaw, nextPitch) {
    yaw = nextYaw;
    pitch = nextPitch;
    camera?.setEulerAngles(pitch, yaw, 0);
}

function moveWithCollision(deltaX, deltaZ, collisionGrid, dungeonWidth, dungeonHeight, tileSize) {
    if (!camera) {
        return null;
    }

    const position = camera.getLocalPosition();
    const current = tileAtWorldPosition(position.x, position.z, collisionGrid, dungeonWidth, dungeonHeight, tileSize);
    let nextX = position.x + deltaX;
    let nextZ = position.z;

    if (!isWalkableWorldPosition({
        x: nextX,
        z: nextZ,
        grid: collisionGrid,
        width: dungeonWidth,
        height: dungeonHeight,
        tileSize,
        radius: props.playerRadius,
        ramps: rampCells,
        fromTile: current,
        fromPosition: position,
    })) {
        nextX = position.x;
    }

    const afterX = tileAtWorldPosition(nextX, position.z, collisionGrid, dungeonWidth, dungeonHeight, tileSize);
    nextZ = position.z + deltaZ;
    if (!isWalkableWorldPosition({
        x: nextX,
        z: nextZ,
        grid: collisionGrid,
        width: dungeonWidth,
        height: dungeonHeight,
        tileSize,
        radius: props.playerRadius,
        ramps: rampCells,
        fromTile: afterX.cell ? afterX : current,
        fromPosition: { x: nextX, z: position.z },
    })) {
        nextZ = position.z;
    }

    const elevation = surfaceElevation(nextX, nextZ, collisionGrid, dungeonWidth, dungeonHeight, tileSize);
    const destination = tileAtWorldPosition(nextX, nextZ, collisionGrid, dungeonWidth, dungeonHeight, tileSize);
    camera.setLocalPosition(nextX, (elevation ?? current.cell?.elevation ?? 0) + 1.55, nextZ);

    return {
        x: destination.x,
        y: destination.y,
        floor: destination.cell?.floor ?? 0,
        elevation: elevation ?? 0,
    };
}

function installInput(canvas) {
    const lockMouse = () => {
        if (document.pointerLockElement || pc.Mouse.isPointerLocked()) {
            emit('lock-change', true);
            return;
        }

        app?.mouse?.enablePointerLock(
            () => emit('lock-change', true),
            () => emit('lock-change', false),
        );
    };
    const onKeyDown = (event) => {
        if (['KeyW', 'KeyA', 'KeyS', 'KeyD'].includes(event.code)) {
            event.preventDefault();
        }
        keys.add(event.code);
    };
    const onKeyUp = (event) => keys.delete(event.code);
    const onPointerLockChange = () => {
        emit('lock-change', Boolean(document.pointerLockElement || pc.Mouse.isPointerLocked()));
    };
    const onPointerLockError = () => emit('lock-change', false);
    const onMouseMove = (event) => {
        if (!document.pointerLockElement && !pc.Mouse.isPointerLocked()) {
            return;
        }

        setRotation(
            yaw - event.dx * 0.12,
            Math.max(-82, Math.min(82, pitch - event.dy * 0.12)),
        );
    };
    const onPointerDown = (event) => emit('pointer-down', event);
    const onPointerUp = (event) => emit('pointer-up', event);
    const onWindowBlur = () => emit('pointer-up', { button: pc.MOUSEBUTTON_LEFT });

    app.mouse.disableContextMenu();
    app.mouse.on(pc.Mouse.EVENT_MOUSEMOVE, onMouseMove);
    app.mouse.on(pc.Mouse.EVENT_MOUSEDOWN, onPointerDown);
    app.mouse.on(pc.Mouse.EVENT_MOUSEUP, onPointerUp);
    window.addEventListener('keydown', onKeyDown);
    window.addEventListener('keyup', onKeyUp);
    window.addEventListener('blur', onWindowBlur);
    document.body.addEventListener('click', lockMouse);
    document.addEventListener('pointerlockchange', onPointerLockChange);
    document.addEventListener('pointerlockerror', onPointerLockError);

    removeListeners = () => {
        app?.mouse?.off(pc.Mouse.EVENT_MOUSEMOVE, onMouseMove);
        app?.mouse?.off(pc.Mouse.EVENT_MOUSEDOWN, onPointerDown);
        app?.mouse?.off(pc.Mouse.EVENT_MOUSEUP, onPointerUp);
        window.removeEventListener('keydown', onKeyDown);
        window.removeEventListener('keyup', onKeyUp);
        window.removeEventListener('blur', onWindowBlur);
        document.body.removeEventListener('click', lockMouse);
        document.removeEventListener('pointerlockchange', onPointerLockChange);
        document.removeEventListener('pointerlockerror', onPointerLockError);
    };
}

function updateMovement(dt) {
    let inputX = 0;
    let inputZ = 0;

    if (keys.has('KeyW')) inputZ += 1;
    if (keys.has('KeyS')) inputZ -= 1;
    if (keys.has('KeyD')) inputX += 1;
    if (keys.has('KeyA')) inputX -= 1;
    if (inputX === 0 && inputZ === 0) {
        return;
    }

    const length = Math.hypot(inputX, inputZ);
    const normalizedX = inputX / length;
    const normalizedZ = inputZ / length;
    const yawRadians = yaw * pc.math.DEG_TO_RAD;
    const sinYaw = Math.sin(yawRadians);
    const cosYaw = Math.cos(yawRadians);
    const speed = 8 * dt;
    const movement = moveWithCollision(
        (normalizedX * cosYaw - normalizedZ * sinYaw) * speed,
        (-normalizedX * sinYaw - normalizedZ * cosYaw) * speed,
        collisionGrid,
        dungeonWidth,
        dungeonHeight,
        tileSize,
    );

    if (movement) {
        emit('move', movement);
    }
}

function getCamera() {
    return camera;
}

function getRotation() {
    return { yaw, pitch };
}

function isMoving() {
    return keys.has('KeyW') || keys.has('KeyA') || keys.has('KeyS') || keys.has('KeyD');
}

function dispose() {
    removeListeners();
    keys.clear();
    rampCells = [];
    lighting = null;
    health = 100;
    app?.off('update', updateMovement);
    camera?.destroy?.();
    playerLight = null;
    camera = null;
    app = null;
}

onBeforeUnmount(dispose);

defineExpose({
    setupPlayer,
    getCamera,
    getRotation,
    isMoving,
    takeDamage,
    getHealth,
    dispose,
});
</script>

<template>
    <div hidden />
</template>
