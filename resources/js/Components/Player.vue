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

const emit = defineEmits(['lock-change', 'move', 'pointer-down', 'pointer-up', 'health-change', 'armor-change', 'damage', 'death']);

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
let armor = 0;
let invulnerability = 0;
let sensitivity = 0.12;
let inputCanvas = null;
let enemySystem = null;
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
    armor = 0;
    invulnerability = 0;
    inputCanvas = canvas;
    yaw = 0;
    pitch = 0;
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
    if (!app || app.gamePaused || health <= 0 || invulnerability > 0) return health;
    const damage = Math.max(0, Number(amount) || 0);
    if (!damage) return health;
    const absorbed = Math.min(armor, Math.ceil(damage * 0.6));
    armor -= absorbed;
    health = Math.max(0, health - (damage - absorbed));
    invulnerability = 0.3;
    emit('armor-change', armor);
    emit('health-change', health);
    emit('damage', damage);
    if (!health) emit('death');
    return health;
}

function heal(amount) {
    if (health <= 0) return false;
    const previous = health;
    health = Math.min(100, health + Math.max(0, amount));
    emit('health-change', health);
    return health > previous;
}

function addArmor(amount) {
    const previous = armor;
    armor = Math.min(100, armor + Math.max(0, amount));
    emit('armor-change', armor);
    return armor > previous;
}

function restoreState(state = {}) {
    health = Math.max(1, Math.min(100, Number(state.health) || 100));
    armor = Math.max(0, Math.min(100, Number(state.armor) || 0));
    emit('health-change', health);
    emit('armor-change', armor);
}

function clearInput() { keys.clear(); }

function requestLock() {
    if (!inputCanvas || health <= 0) return;
    if (document.pointerLockElement === inputCanvas) {
        emit('lock-change', true);
        return;
    }
    try {
        const request = inputCanvas.requestPointerLock();
        request?.catch?.(() => emit('lock-change', false));
    } catch { emit('lock-change', false); }
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

    if (blockedByEnemy(nextX, nextZ, position)) nextX = position.x;
    if (blockedByEnemy(nextX, nextZ, { x: nextX, z: position.z })) nextZ = position.z;
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

function blockedByEnemy(x, z, fromPosition) {
    if (!enemySystem || !fromPosition) return false;
    const floorY = surfaceElevation(x, z, collisionGrid, dungeonWidth, dungeonHeight, tileSize);
    if (floorY === null) return false;
    return (enemySystem.getEnemies?.() || []).some((enemy) => {
        if (!enemy.position) return false;
        if (Math.abs(floorY - (Number(enemy.position.y) || 0)) > 1.35) return false;
        const radius = props.playerRadius + (Number(enemy.radius) || 0.45);
        const nextDistance = Math.hypot(x - enemy.position.x, z - enemy.position.z);
        const currentDistance = Math.hypot(fromPosition.x - enemy.position.x, fromPosition.z - enemy.position.z);
        return nextDistance < radius && nextDistance < currentDistance - 0.001;
    });
}

function installInput(canvas) {
    const onKeyDown = (event) => {
        if (app?.gamePaused || health <= 0) return;
        if (['KeyW', 'KeyA', 'KeyS', 'KeyD'].includes(event.code)) {
            event.preventDefault();
        }
        keys.add(event.code);
    };
    const onKeyUp = (event) => keys.delete(event.code);
    const onPointerLockChange = () => {
        clearInput();
        emit('lock-change', document.pointerLockElement === canvas);
    };
    const onPointerLockError = () => emit('lock-change', false);
    const onMouseMove = (event) => {
        if (app?.gamePaused || document.pointerLockElement !== canvas) {
            return;
        }

        setRotation(
            yaw - event.dx * sensitivity,
            Math.max(-75, Math.min(75, pitch - event.dy * sensitivity)),
        );
    };
    const onPointerDown = (event) => { if (!app?.gamePaused) emit('pointer-down', event); };
    const onPointerUp = (event) => emit('pointer-up', event);
    const onWindowBlur = () => { clearInput(); emit('pointer-up', { button: pc.MOUSEBUTTON_LEFT }); };

    app.mouse.disableContextMenu();
    app.mouse.on(pc.Mouse.EVENT_MOUSEMOVE, onMouseMove);
    app.mouse.on(pc.Mouse.EVENT_MOUSEDOWN, onPointerDown);
    app.mouse.on(pc.Mouse.EVENT_MOUSEUP, onPointerUp);
    window.addEventListener('keydown', onKeyDown);
    window.addEventListener('keyup', onKeyUp);
    window.addEventListener('blur', onWindowBlur);
    document.addEventListener('pointerlockchange', onPointerLockChange);
    document.addEventListener('pointerlockerror', onPointerLockError);

    removeListeners = () => {
        app?.mouse?.off(pc.Mouse.EVENT_MOUSEMOVE, onMouseMove);
        app?.mouse?.off(pc.Mouse.EVENT_MOUSEDOWN, onPointerDown);
        app?.mouse?.off(pc.Mouse.EVENT_MOUSEUP, onPointerUp);
        window.removeEventListener('keydown', onKeyDown);
        window.removeEventListener('keyup', onKeyUp);
        window.removeEventListener('blur', onWindowBlur);
        document.removeEventListener('pointerlockchange', onPointerLockChange);
        document.removeEventListener('pointerlockerror', onPointerLockError);
    };
}

function updateMovement(dt) {
    if (!app || app.gamePaused || !camera || health <= 0) return;
    dt = Math.min(dt, 0.05);
    invulnerability = Math.max(0, invulnerability - dt);
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
    const speed = (keys.has('ShiftLeft') || keys.has('ShiftRight') ? 11.5 : 8) * dt;
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
    return !app?.gamePaused && (keys.has('KeyW') || keys.has('KeyA') || keys.has('KeyS') || keys.has('KeyD'));
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
    inputCanvas = null;
    enemySystem = null;
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
    getArmor: () => armor,
    heal,
    addArmor,
    restoreState,
    requestLock,
    clearInput,
    setRotation,
    setSensitivity: (value) => { sensitivity = Math.max(0.03, Math.min(0.4, value)); },
    setEnemySystem: (system) => { enemySystem = system; },
    dispose,
});
</script>

<template>
    <div hidden />
</template>
