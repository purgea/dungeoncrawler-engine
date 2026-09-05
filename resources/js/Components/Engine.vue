<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, toRaw } from 'vue';
import * as pc from 'playcanvas';
import DungeonRenderer from './DungeonRenderer.vue';
import Enemy from './Enemy.vue';
import Loader from './Loader.vue';
import Minimap from './Minimap.vue';
import Player from './Player.vue';
import Weapon from './Weapon.vue';
import GameHud from './GameHud.vue';
import { colorFromRgb, fogTypeFromName } from '../lighting';
import { WorldObjects } from '../game/WorldObjects.js';
import { GameAudio } from '../game/Audio.js';
import { readSettings, saveSettings } from '../game/RunState.js';
import { GridPathfinder } from '../ai/GridPathfinder.js';

const emit = defineEmits(['lock-change', 'checkpoint', 'complete', 'restart', 'next', 'home', 'mute-change']);
const props = defineProps({
    dungeon: { type: Object, required: true }, campaign: { type: Object, default: () => ({}) },
    initialState: { type: Object, default: () => ({}) },
});
const viewport = ref(null), app = shallowRef(null);
const playerComponent = ref(null), enemyComponent = ref(null), dungeonRenderer = ref(null), loaderComponent = ref(null), weaponComponent = ref(null);
const minimapGrid = shallowRef([]), minimapPlayer = ref(null), minimapRevision = ref(0), markers = ref([]);
const startingWeapon = props.dungeon.definitions?.weapon?.find((definition) => definition.starting) || props.dungeon.definitions?.weapon?.[0] || {};
const maxMana = Number(props.dungeon.definitions?.rule?.find((definition) => definition.id === 'gate')?.max_mana) || 100;
const playerHealth = ref(100), playerArmor = ref(0), weaponState = ref({
    id: startingWeapon.id,
    name: startingWeapon.name,
    mana: maxMana * 0.6,
    maxMana,
    unlocked: startingWeapon.id ? [startingWeapon.id] : [],
    weapons: props.dungeon.definitions?.weapon || [],
});
const status = ref('loading'), hasStarted = ref(false), elapsed = ref(0), kills = ref(0), sigils = ref(0), mapOpen = ref(false);
const message = ref(''), prompt = ref(''), damageFlash = ref(0), pickupFlash = ref(0), hitFlash = ref(0), target = ref(null);
const settings = ref(readSettings());
const exploredTiles = new Set();
const audio = new GameAudio();
let collisionGrid = [], resizeObserver = null, leftMouseHeld = false, world = null, disposed = false;
let messageTimer = 0, targetTimer = 0, hudTimer = 0, playTime = 0, navigationTimer = 0, pathfinder = null;
const navigationPath = shallowRef([]), navigationHint = ref('');
const objective = computed(() => sigils.value >= props.dungeon.requiredSigils ? `Gate unsealed · ${navigationHint.value || 'Find the exit portal'}` : `${sigils.value} / ${props.dungeon.requiredSigils} sigils recovered · ${navigationHint.value || 'Explore the ruins'}`);
const nextFrame = () => new Promise(resolve => requestAnimationFrame(resolve));

function notify(text, duration = 3) { message.value = text; messageTimer = duration; }
function snapshot() { return { health: playerHealth.value, armor: playerArmor.value, weapon: weaponComponent.value?.getState?.() || weaponState.value }; }
function tileFromPosition(position) {
    const { tileSize, width, height } = props.dungeon;
    const x = Math.floor(position.x / tileSize + width / 2 + 0.5), y = Math.floor(position.z / tileSize + height / 2 + 0.5);
    return { x, y, floor: collisionGrid[y]?.[x]?.floor ?? 0, yaw: playerComponent.value?.getRotation?.().yaw ?? 0 };
}
function revealAroundPlayer(force = false) {
    const camera = playerComponent.value?.getCamera?.();
    if (!camera || !collisionGrid.length) return;
    const tile = tileFromPosition(camera.getPosition());
    const previous = minimapPlayer.value;
    if (!force && previous?.x === tile.x && previous?.y === tile.y && previous?.floor === tile.floor) {
        minimapPlayer.value = { ...previous, yaw: tile.yaw };
        return;
    }
    minimapPlayer.value = tile;
    for (let y = tile.y - 3; y <= tile.y + 3; y++) for (let x = tile.x - 3; x <= tile.x + 3; x++) {
        const cell = collisionGrid[y]?.[x];
        if (Math.hypot(x - tile.x, y - tile.y) <= 3.25 && cell?.walkable && cell.floor === tile.floor) exploredTiles.add(`${cell.floor}:${x}:${y}`);
    }
    minimapRevision.value++;
    navigationTimer = 0;
}
function updateNavigation() {
    if (!pathfinder || !minimapPlayer.value) return;
    const destinations = sigils.value >= props.dungeon.requiredSigils ? [props.dungeon.exit] : world.pickups.filter(p => p.type === 'sigil' && !p.collected);
    let best = [];
    for (const destination of destinations) {
        if (!destination) continue;
        const path = pathfinder.findPath(minimapPlayer.value, destination);
        if (path.length && (!best.length || path.length < best.length)) best = path;
    }
    navigationPath.value = best;
    const distance = Math.max(0, best.length - 1) * props.dungeon.tileSize;
    navigationHint.value = best.length ? `${distance}m to ${sigils.value >= props.dungeon.requiredSigils ? 'gate' : 'nearest sigil'}` : '';
}
function resizeCanvasToViewport() {
    if (app.value && viewport.value) app.value.resizeCanvas(Math.max(1, viewport.value.clientWidth), Math.max(1, viewport.value.clientHeight));
}
function onPlayerPointerDown(event) {
    if (event.button !== pc.MOUSEBUTTON_LEFT || status.value !== 'playing') return;
    leftMouseHeld = true;
    weaponComponent.value?.fire?.(playerComponent.value?.getCamera?.());
}
function pause() {
    if (status.value === 'playing') status.value = 'paused';
    if (app.value) app.value.gamePaused = true;
    leftMouseHeld = false;
    playerComponent.value?.clearInput?.();
    emit('lock-change', false);
}
function onPlayerLockChange(locked) {
    if (locked && ['ready', 'paused'].includes(status.value)) {
        status.value = 'playing';
        hasStarted.value = true;
        app.value.gamePaused = false;
        emit('lock-change', true);
    } else if (!locked) pause();
}
function resume() {
    if (!['ready', 'paused'].includes(status.value)) return;
    audio.unlock();
    playerComponent.value?.requestLock?.();
}
function releasePointer() {
    leftMouseHeld = false;
    if (app.value) app.value.gamePaused = true;
    playerComponent.value?.clearInput?.();
    if (document.pointerLockElement) document.exitPointerLock?.();
    emit('lock-change', false);
}
function onDeath() {
    status.value = 'dead';
    releasePointer();
}
function completeLevel() {
    if (status.value !== 'playing' || !world?.nearPortal(playerComponent.value.getCamera())) return;
    if (sigils.value < props.dungeon.requiredSigils) {
        notify(`The gate requires ${props.dungeon.requiredSigils - sigils.value} more sigil${props.dungeon.requiredSigils - sigils.value === 1 ? '' : 's'}.`);
        return;
    }
    status.value = 'complete';
    elapsed.value = Math.floor(playTime);
    audio.play('portal');
    releasePointer();
    emit('complete', { ...snapshot(), kills: kills.value, elapsed: playTime });
}
function onPickup(item) {
    let accepted = false, text = '';
    switch (item.type) {
        case 'health': accepted = playerComponent.value.heal(item.amount || 0); text = `+${item.amount || 0} vitality`; break;
        case 'armor': accepted = playerComponent.value.addArmor(item.amount || 0); text = `+${item.amount || 0} armor`; break;
        case 'mana': accepted = Boolean(weaponComponent.value.addMana(item.amount || 0)); text = `+${item.amount || 0} mana`; break;
        case 'weapon':
            accepted = weaponComponent.value.unlockWeapon(item.weapon);
            if (!accepted) accepted = Boolean(weaponComponent.value.addMana(20));
            else weaponComponent.value.addMana(15);
            text = `${item.name || item.weapon} · weapon unlocked`;
            break;
        case 'sigil':
            sigils.value++;
            accepted = true;
            text = `Sigil recovered · ${sigils.value} / ${props.dungeon.requiredSigils}`;
            if (sigils.value >= props.dungeon.requiredSigils) {
                world.openPortal();
                text = 'All sigils recovered. The gate is unsealed.';
            }
            navigationTimer = 0;
            break;
    }
    if (!accepted) return false;
    audio.play(item.type === 'sigil' ? 'sigil' : 'pickup');
    pickupFlash.value = 0.6;
    notify(text, item.type === 'sigil' ? 4 : 2.8);
    // Markers are refreshed after WorldObjects marks the pickup as collected.
    return true;
}
function onEnemyKill(enemy) {
    kills.value++;
    audio.play('kill');
    if (enemy.type === 'warden') notify(`${enemy.name || 'The guardian'} has fallen.`, 3);
    // A small on-kill return rewards aggressive play without a forced ammo grind.
    weaponComponent.value?.addMana?.(3);
}
function onHit(event) { target.value = event; targetTimer = 2; hitFlash.value = 0.18; audio.play('hit'); }
function onDamage() { damageFlash.value = 0.9; audio.play('hurt'); }
function toggleMute() {
    settings.value.muted = !settings.value.muted;
    audio.muted = settings.value.muted;
    saveSettings(settings.value);
    emit('mute-change', settings.value.muted);
}
function setSensitivity(value) {
    settings.value.sensitivity = value;
    playerComponent.value?.setSensitivity(value);
    saveSettings(settings.value);
}
function onKeyDown(event) {
    if (status.value !== 'playing') return;
    if (['Tab', 'KeyM', 'KeyE', 'Digit1', 'Digit2', 'Digit3'].includes(event.code)) event.preventDefault();
    if (event.repeat) return;
    if (event.code === 'Tab' || event.code === 'KeyM') mapOpen.value = !mapOpen.value;
    if (event.code === 'KeyE') completeLevel();
    if (/^Digit[123]$/.test(event.code)) {
        const slot = Number(event.code.slice(-1));
        if (!weaponComponent.value.selectWeapon(slot) && !(weaponState.value.weapons || []).find(w => w.slot === slot)?.unlocked) notify('Find this weapon in the dungeon.', 1.5);
    }
}
function onWheel(event) {
    if (status.value !== 'playing') return;
    event.preventDefault();
    weaponComponent.value?.cycleWeapon(event.deltaY < 0 ? -1 : 1);
}
function onVisibilityChange() { if (document.hidden) { pause(); releasePointer(); } }
function onBlur() { pause(); releasePointer(); }
function updateWorld(rawDt) {
    if (disposed || !app.value || app.value.gamePaused || status.value !== 'playing') return;
    const dt = Math.min(rawDt, 0.05);
    const camera = playerComponent.value?.getCamera?.();
    if (!camera) return;
    playTime += dt;
    dungeonRenderer.value.updateDecorations(playerComponent.value.getRotation().yaw);
    dungeonRenderer.value.updateLighting(dt);
    weaponComponent.value.setMoving(playerComponent.value.isMoving());
    if (leftMouseHeld) weaponComponent.value.fire(camera);
    world.update(dt, camera, {
        onPickup,
        onTrap: trap => { playerComponent.value.takeDamage(trap.damage || 0); },
        onWarning: () => audio.play('warning'),
    });
    damageFlash.value = Math.max(0, damageFlash.value - dt * 2.2);
    pickupFlash.value = Math.max(0, pickupFlash.value - dt * 1.3);
    hitFlash.value = Math.max(0, hitFlash.value - dt);
    messageTimer -= dt;
    targetTimer -= dt;
    if (messageTimer <= 0) message.value = '';
    if (targetTimer <= 0) target.value = null;
    prompt.value = world.nearPortal(camera) ? sigils.value >= props.dungeon.requiredSigils ? 'Enter the unsealed gate' : `${props.dungeon.requiredSigils - sigils.value} more sigils needed` : '';
    hudTimer -= dt;
    navigationTimer -= dt;
    if (hudTimer <= 0) {
        elapsed.value = Math.floor(playTime);
        revealAroundPlayer();
        markers.value = world.markers();
        hudTimer = 0.1;
    }
    if (navigationTimer <= 0) { updateNavigation(); navigationTimer = 1; }
}

async function start() {
    loaderComponent.value.setMessage('Awakening the ruins…');
    const canvas = document.createElement('canvas');
    canvas.className = 'dungeon-canvas';
    canvas.style.cssText = 'display:block;width:100%;height:100%';
    canvas.setAttribute('aria-label', 'First-person dungeon. Enter the dungeon to play.');
    viewport.value.appendChild(canvas);
    const instance = new pc.Application(canvas, { keyboard: new pc.Keyboard(window), mouse: new pc.Mouse(canvas) });
    app.value = instance;
    instance.gamePaused = true;
    instance.maxDeltaTime = 0.05;
    instance.graphicsDevice.maxPixelRatio = Math.min(window.devicePixelRatio || 1, 1.5);
    instance.setCanvasFillMode(pc.FILLMODE_NONE);
    instance.setCanvasResolution(pc.RESOLUTION_AUTO);
    instance.start();
    resizeCanvasToViewport();
    resizeObserver = new ResizeObserver(resizeCanvasToViewport);
    resizeObserver.observe(viewport.value);
    const layout = toRaw(props.dungeon), lighting = layout.lighting;
    instance.scene.ambientLight = colorFromRgb(lighting.scene.ambient);
    instance.scene.exposure = lighting.scene.exposure;
    instance.scene.fog.type = fogTypeFromName(lighting.scene.fog.type);
    instance.scene.fog.color = colorFromRgb(lighting.scene.fog.color);
    instance.scene.fog.density = lighting.scene.fog.density;
    instance.scene.fog.start = lighting.scene.fog.start;
    instance.scene.fog.end = lighting.scene.fog.end;
    await nextFrame();
    if (disposed) return;
    const renderer = dungeonRenderer.value;
    const material = renderer.createMaterial(renderer.createDungeonTexture(instance));
    const stoneMaterial = await renderer.loadStoneMaterial(instance);
    if (disposed) return;
    collisionGrid = layout.grid;
    minimapGrid.value = layout.grid;
    loaderComponent.value.setMessage('Carving the halls…');
    await renderer.buildDungeon(instance, layout.grid, material, stoneMaterial, layout.decorations || []);
    if (disposed) return;
    const config = { grid: collisionGrid, width: layout.width, height: layout.height, tileSize: layout.tileSize, wallHeight: layout.wallHeight, lighting, definitions: layout.definitions || {} };
    const spawn = renderer.worldPosition(layout.spawn.x, layout.spawn.y, layout.spawn.floor);
    playerComponent.value.setupPlayer(instance, canvas, { ...spawn, y: spawn.y + 1.55 }, config);
    playerComponent.value.restoreState(toRaw(props.initialState));
    playerComponent.value.setSensitivity(settings.value.sensitivity);
    loaderComponent.value.setMessage('Summoning the restless…');
    enemyComponent.value.setupEnemies(instance, playerComponent.value, layout.enemies || [], config);
    playerComponent.value.setEnemySystem(enemyComponent.value);
    const manaLimit = layout.definitions?.rule?.find((definition) => definition.id === 'gate')?.max_mana;
    const weaponDefinitions = (layout.definitions?.weapon || []).filter((definition) => definition.path_url);
    await weaponComponent.value.setupWeapon(instance, playerComponent.value.getCamera(), toRaw(weaponDefinitions), lighting, { definitions: layout.definitions?.weapon || [], maxMana: manaLimit });
    if (disposed) return;
    weaponComponent.value.setCollisionGrid(collisionGrid, layout.width, layout.height, layout.tileSize, layout.wallHeight);
    weaponComponent.value.setEnemySystem(enemyComponent.value);
    if (props.initialState.weapon) weaponComponent.value.restoreState(toRaw(props.initialState.weapon));
    weaponState.value = weaponComponent.value.getState();
    world = new WorldObjects(instance, layout);
    markers.value = world.markers();
    pathfinder = new GridPathfinder(collisionGrid, layout.width, layout.height);
    revealAroundPlayer(true);
    updateNavigation();
    // Start facing the first step toward an objective, rather than a random wall.
    if (navigationPath.value.length > 1) {
        const next = navigationPath.value[1], origin = layout.spawn;
        playerComponent.value.setRotation(Math.atan2(-(next.x - origin.x), -(next.y - origin.y)) * 180 / Math.PI, 0);
    }
    dungeonRenderer.value.updateDecorations(playerComponent.value.getRotation().yaw);
    audio.muted = settings.value.muted;
    emit('mute-change', settings.value.muted);
    instance.on('update', updateWorld);
    window.addEventListener('keydown', onKeyDown);
    canvas.addEventListener('wheel', onWheel, { passive: false });
    window.addEventListener('blur', onBlur);
    document.addEventListener('visibilitychange', onVisibilityChange);
    loaderComponent.value.hide();
    status.value = 'ready';
    emit('checkpoint', snapshot());
}
function cleanup() {
    if (disposed) return;
    disposed = true;
    releasePointer();
    resizeObserver?.disconnect();
    window.removeEventListener('keydown', onKeyDown);
    window.removeEventListener('blur', onBlur);
    document.removeEventListener('visibilitychange', onVisibilityChange);
    app.value?.graphicsDevice?.canvas?.removeEventListener('wheel', onWheel);
    app.value?.off('update', updateWorld);
    weaponComponent.value?.cleanup?.();
    enemyComponent.value?.cleanup?.();
    world?.dispose();
    playerComponent.value?.dispose?.();
    audio.dispose();
    app.value?.destroy?.();
    app.value = null;
}
onMounted(async () => {
    await nextTick();
    try { await start(); } catch (error) {
        if (disposed) return;
        loaderComponent.value?.fail(error);
        console.error('Unable to initialize dungeon.', error);
    }
});
onBeforeUnmount(cleanup);
defineExpose({ cleanup });
</script>

<template>
    <div class="absolute inset-0 h-full w-full">
        <div ref="viewport" class="absolute inset-0 h-full w-full" />
        <Player ref="playerComponent" :player-radius="0.48" @lock-change="onPlayerLockChange" @pointer-down="onPlayerPointerDown" @pointer-up="leftMouseHeld = false" @health-change="playerHealth = $event" @armor-change="playerArmor = $event" @damage="onDamage" @death="onDeath" />
        <Enemy ref="enemyComponent" @kill="onEnemyKill" @hit="onHit" />
        <Weapon ref="weaponComponent" @state-change="weaponState = $event" @shot="audio.play($event.id)" @empty="audio.play('empty'); notify('Mana depleted. Aether Wand equipped.', 2)" />
        <DungeonRenderer ref="dungeonRenderer" :layout="dungeon" />
        <Minimap v-if="status === 'playing'" :grid="minimapGrid" :explored="exploredTiles" :player="minimapPlayer" :revision="minimapRevision" :markers="markers" :exit="dungeon.exit" :expanded="mapOpen" :path="navigationPath" />
        <GameHud :status="status" :campaign="campaign" :health="playerHealth" :armor="playerArmor" :weapon="weaponState" :kills="kills" :enemy-count="dungeon.enemies?.length || 0" :sigils="sigils" :required-sigils="dungeon.requiredSigils" :elapsed="elapsed" :message="message" :prompt="prompt" :damage-flash="damageFlash" :pickup-flash="pickupFlash" :hit-flash="hitFlash" :target="target" :muted="settings.muted" :sensitivity="settings.sensitivity" :has-started="hasStarted" :map-open="mapOpen" :objective="objective" @resume="resume" @restart="emit('restart')" @next="emit('next')" @home="emit('home')" @mute="toggleMute" @sensitivity="setSensitivity" />
        <Loader ref="loaderComponent" />
    </div>
</template>
