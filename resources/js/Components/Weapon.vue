<script setup>
import { onBeforeUnmount } from 'vue';
import * as pc from 'playcanvas';
import crossbowUrl from '../../../extras/weapons/crossbow.png';
import { Arsenal, WEAPONS } from '../game/Weapons.js';
import { pointOnSegment, traceDungeonSegment } from '../game/Combat.js';

const emit = defineEmits(['state-change', 'shot', 'hit', 'empty']);
let app = null;
let camera = null;
let enemySystem = null;
let weapon = null;
let flash = null;
let flashLight = null;
let overlayLayer = null;
let arsenal = new Arsenal();
let dungeon = { grid: [], width: 0, height: 0, tileSize: 1, wallHeight: 3.3 };
let bobTime = 0;
let moving = false;
let movementBlend = 0;
let recoil = 0;
let switchDip = 0;
let flashTime = 0;
let generation = 0;
const projectiles = new Set();
const effects = new Set();
const textures = new Map();
const spriteMaterials = new Map();
const projectileMaterials = new Map();
const ownedTextures = new Set();
const ownedMaterials = new Set();
const loadedAssets = new Set();
const cancelLoads = new Set();

function polygon(ctx, points, fill, stroke = '#221b21', width = 3) {
    ctx.beginPath();
    points.forEach(([x, y], index) => index ? ctx.lineTo(x, y) : ctx.moveTo(x, y));
    ctx.closePath();
    ctx.fillStyle = fill;
    ctx.fill();
    if (stroke) {
        ctx.strokeStyle = stroke;
        ctx.lineWidth = width;
        ctx.stroke();
    }
}

// Original canvas artwork keeps the other relics available without asset downloads.
function createRelicCanvas(id) {
    const canvas = document.createElement('canvas');
    canvas.width = 384;
    canvas.height = 512;
    const ctx = canvas.getContext('2d');
    const ember = id === 'emberstaff';
    if (id === 'crossbow') {
        ctx.translate(192, 305);
        ctx.strokeStyle = '#b08b42';
        ctx.lineWidth = 20;
        ctx.beginPath();
        ctx.moveTo(-176, 45);
        ctx.quadraticCurveTo(0, -48, 176, 45);
        ctx.stroke();
        ctx.strokeStyle = '#e1cd87';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(-176, 45);
        ctx.lineTo(0, 115);
        ctx.lineTo(176, 45);
        ctx.stroke();
        polygon(ctx, [[-22, 180], [-13, -12], [0, -60], [13, -12], [22, 180]], '#564333');
        polygon(ctx, [[-10, 10], [0, -20], [10, 10], [0, 33]], '#7af6bc', '#d9b769');
        return canvas;
    }

    // Angular brass fittings, carved grip and a faceted crystal form a readable silhouette.
    ctx.translate(30, 0);
    const shaft = ctx.createLinearGradient(210, 0, 270, 0);
    shaft.addColorStop(0, '#201f25');
    shaft.addColorStop(0.35, '#685348');
    shaft.addColorStop(0.55, '#9b7a51');
    shaft.addColorStop(1, '#27222b');
    polygon(ctx, [[198, 234], [228, 226], [280, 512], [215, 512]], shaft, '#181821', 5);
    for (let i = 0; i < 12; i += 1) {
        const y = 266 + i * 19;
        const x = 204 + i * 2.6;
        polygon(ctx, [[x, y], [x + 37, y - 6], [x + 39, y + 1], [x + 1, y + 8]], i % 3 === 0 ? '#ba9659' : '#433a3a', '#29232b', 1);
    }
    const glow = ember ? '#ff702e' : '#65d9ff';
    ctx.shadowColor = glow;
    ctx.shadowBlur = 28;
    polygon(ctx, [[173, 174], [186, 104], [207, 78], [236, 116], [246, 171], [211, 205]], glow, null);
    ctx.shadowBlur = 0;
    const gold = ctx.createLinearGradient(165, 0, 250, 0);
    gold.addColorStop(0, '#564731');
    gold.addColorStop(0.45, '#e0c48c');
    gold.addColorStop(0.66, '#9b7446');
    gold.addColorStop(1, '#443734');
    polygon(ctx, [[170, 135], [179, 183], [208, 212], [241, 182], [251, 129], [264, 183], [237, 229], [192, 239], [158, 185]], gold);
    polygon(ctx, [[184, 153], [194, 114], [207, 87], [219, 144], [211, 194]], ember ? '#ffc171' : '#c3f6ff', null);
    polygon(ctx, [[207, 87], [236, 123], [241, 163], [211, 194], [219, 144]], ember ? '#ef632d' : '#42a9e0', null);
    polygon(ctx, [[194, 114], [184, 153], [211, 194], [199, 148]], ember ? '#ba302a' : '#376eb0', null);
    polygon(ctx, [[185, 231], [235, 222], [240, 240], [189, 250]], gold);
    if (ember) {
        polygon(ctx, [[165, 174], [151, 134], [156, 83], [168, 49], [163, 104], [182, 148]], gold);
        polygon(ctx, [[244, 166], [262, 126], [257, 72], [271, 102], [276, 153], [260, 187]], gold);
        ctx.fillStyle = '#ffeab5';
        for (let i = 0; i < 5; i += 1) ctx.fillRect(202 + i * 4, 263 + i * 22, 3, 10);
    }
    // A leather gauntlet anchors the relic to the bottom of the viewport.
    polygon(ctx, [[195, 430], [214, 414], [241, 415], [254, 435], [280, 452], [294, 512], [179, 512], [177, 470]], '#393442', '#171820', 5);
    polygon(ctx, [[196, 435], [211, 429], [250, 441], [260, 458], [211, 449]], '#756459', '#26222c', 2);
    polygon(ctx, [[185, 483], [280, 477], [289, 500], [181, 507]], gold);
    for (const x of [197, 216, 236, 256, 273]) {
        ctx.fillStyle = '#d7bc84';
        ctx.fillRect(x, 489, 4, 4);
    }
    return canvas;
}

function textureFromCanvas(canvas) {
    const texture = new pc.Texture(app.graphicsDevice, {
        width: canvas.width,
        height: canvas.height,
        format: pc.PIXELFORMAT_R8_G8_B8_A8,
        mipmaps: false,
    });
    texture.addressU = pc.ADDRESS_CLAMP_TO_EDGE;
    texture.addressV = pc.ADDRESS_CLAMP_TO_EDGE;
    texture.minFilter = pc.FILTER_LINEAR;
    texture.magFilter = pc.FILTER_LINEAR;
    texture.setSource(canvas);
    ownedTextures.add(texture);
    return texture;
}

function loadTexture(url) {
    const owner = app;
    return new Promise((resolve) => {
        const asset = new pc.Asset(`weapon-${url}`, 'texture', { url });
        let completed = false;
        const finish = (texture) => {
            if (completed) return;
            completed = true;
            cancelLoads.delete(cancel);
            asset.off('load', onLoad);
            asset.off('error', onError);
            resolve(texture);
        };
        const cancel = () => finish(null);
        const onLoad = () => finish(asset.resource);
        const onError = () => finish(null);
        cancelLoads.add(cancel);
        loadedAssets.add(asset);
        asset.once('load', onLoad);
        asset.once('error', onError);
        owner.assets.add(asset);
        owner.assets.load(asset);
    });
}

function spriteMaterial(texture, additive = false) {
    const material = new pc.StandardMaterial();
    material.diffuseMap = texture;
    material.emissiveMap = texture;
    material.emissive.set(1, 1, 1);
    material.useLighting = false;
    material.opacityMap = texture;
    material.opacityMapChannel = 'a';
    material.alphaTest = 0.025;
    material.blendType = additive ? pc.BLEND_ADDITIVEALPHA : pc.BLEND_NORMAL;
    material.depthWrite = false;
    material.depthTest = false;
    material.cull = pc.CULLFACE_NONE;
    material.update();
    ownedMaterials.add(material);
    return material;
}

function makeProjectileMaterial(color) {
    const material = new pc.StandardMaterial();
    material.diffuse.set(...color);
    material.emissive.set(...color);
    material.emissiveIntensity = 2.5;
    material.useLighting = false;
    material.update();
    ownedMaterials.add(material);
    return material;
}

function createFlash() {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 128;
    const ctx = canvas.getContext('2d');
    const gradient = ctx.createRadialGradient(64, 64, 0, 64, 64, 62);
    gradient.addColorStop(0, 'rgba(255,255,255,1)');
    gradient.addColorStop(0.13, 'rgba(255,255,255,.95)');
    gradient.addColorStop(0.35, 'rgba(255,255,255,.4)');
    gradient.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, 128, 128);
    flash = new pc.Entity('weapon-muzzle-flash');
    flash.addComponent('render', { type: 'plane', material: spriteMaterial(textureFromCanvas(canvas), true), layers: [overlayLayer.id] });
    flash.setLocalEulerAngles(90, 0, 0);
    flash.render.meshInstances.forEach((instance) => { instance.drawOrder = 2; });
    flash.enabled = false;
    camera.addChild(flash);
    flashLight = new pc.Entity('weapon-muzzle-light');
    flashLight.addComponent('light', { type: 'omni', intensity: 0, range: 5, castShadows: false });
    camera.addChild(flashLight);
}

async function setupWeapon(appInstance, cameraEntity, weaponAssets = [], lightingConfig = {}, options = {}) {
    cleanup();
    const setupGeneration = generation;
    app = appInstance;
    camera = cameraEntity;
    arsenal = new Arsenal(options.state || {});
    enemySystem = options.enemySystem || null;
    overlayLayer = new pc.Layer({ name: 'First person weapons', opaqueSortMode: pc.SORTMODE_MANUAL, transparentSortMode: pc.SORTMODE_MANUAL, clearDepthBuffer: true });
    app.scene.layers.push(overlayLayer);
    camera.camera.layers = [...camera.camera.layers, overlayLayer.id];
    for (const definition of WEAPONS) {
        const texture = textureFromCanvas(createRelicCanvas(definition.id));
        textures.set(definition.id, texture);
        spriteMaterials.set(definition.id, spriteMaterial(texture));
        projectileMaterials.set(definition.id, makeProjectileMaterial(definition.color));
    }
    weapon = new pc.Entity('player-weapon');
    weapon.addComponent('render', { type: 'plane', material: spriteMaterials.get(arsenal.id), layers: [overlayLayer.id] });
    weapon.render.meshInstances.forEach((instance) => { instance.drawOrder = 1; });
    weapon.setLocalEulerAngles(90, 0, 0);
    camera.addChild(weapon);
    createFlash();
    applySelection();
    app.on('update', updateWeapon);
    emitState();
    const asset = weaponAssets.find((item) => /crossbow/i.test(item.path_url || item.path || item.key || ''));
    const crossbow = await loadTexture(asset?.path_url || crossbowUrl);
    if (setupGeneration !== generation || !app || !crossbow) return;
    crossbow.addressU = pc.ADDRESS_CLAMP_TO_EDGE;
    crossbow.addressV = pc.ADDRESS_CLAMP_TO_EDGE;
    crossbow.minFilter = pc.FILTER_LINEAR;
    crossbow.magFilter = pc.FILTER_LINEAR;
    textures.set('crossbow', crossbow);
    const material = spriteMaterials.get('crossbow');
    material.diffuseMap = crossbow;
    material.emissiveMap = crossbow;
    material.opacityMap = crossbow;
    material.update();
    applySelection();
}

function applySelection() {
    if (!weapon) return;
    const crossbow = arsenal.id === 'crossbow';
    const height = crossbow ? 1.62 : 1.36;
    const texture = textures.get(arsenal.id);
    weapon.render.material = spriteMaterials.get(arsenal.id);
    weapon.setLocalScale(height * texture.width / texture.height, 1, height);
    switchDip = 0.2;
    poseWeapon(0);
}

function poseWeapon(dt) {
    movementBlend += ((moving ? 1 : 0) - movementBlend) * Math.min(dt * 12, 1);
    if (moving) bobTime += dt * 9;
    recoil = Math.max(0, recoil - dt * 6);
    switchDip = Math.max(0, switchDip - dt * 1.8);
    const crossbow = arsenal.id === 'crossbow';
    weapon.setLocalPosition(
        (crossbow ? 0 : 0.09) + Math.sin(bobTime) * 0.019 * movementBlend,
        (crossbow ? -0.17 : -0.20) + Math.abs(Math.cos(bobTime)) * 0.019 * movementBlend - recoil * 0.065 - switchDip,
        -0.98 + recoil * 0.07,
    );
    weapon.setLocalEulerAngles(90, 0, Math.sin(bobTime) * movementBlend * 0.5 + recoil * (crossbow ? 0 : -2));
    flashTime = Math.max(0, flashTime - dt);
    flash.enabled = flashTime > 0;
    if (flash.enabled) {
        const definition = arsenal.weapon;
        const size = (definition.id === 'emberstaff' ? 0.4 : 0.25) * (0.4 + flashTime / 0.09);
        flash.setLocalScale(size, 1, size);
        flash.setLocalPosition(crossbow ? 0 : 0.16, crossbow ? -0.16 : 0.21, -0.82);
        flash.render.material.emissive.set(...definition.color);
        flashLight.light.color = new pc.Color(...definition.color);
    }
    flashLight.light.intensity = flashTime * 30;
}

function spawnEffect(position, definition, size, life, velocity = null, expand = false) {
    const entity = new pc.Entity('weapon-spark');
    entity.addComponent('render', { type: 'sphere', material: projectileMaterials.get(definition.id) });
    entity.setPosition(position.x, position.y, position.z);
    entity.setLocalScale(size, size, size);
    app.root.addChild(entity);
    effects.add({ entity, size, life, age: 0, velocity, expand });
}

function impact(position, definition) {
    spawnEffect(position, definition, definition.radius * 1.8, 0.14, null, true);
    for (let index = 0; index < (definition.id === 'emberstaff' ? 9 : 4); index += 1) {
        const angle = Math.random() * Math.PI * 2;
        const speed = 1.5 + Math.random() * 2.5;
        spawnEffect(position, definition, 0.035 + definition.radius * 0.15, 0.2 + Math.random() * 0.15, new pc.Vec3(Math.sin(angle) * speed, (Math.random() - 0.2) * speed, Math.cos(angle) * speed));
    }
}

function fire(cameraEntity = camera) {
    if (!app || app.gamePaused || !weapon || !cameraEntity) return false;
    const result = arsenal.fire();
    if (!result.fired) {
        if (result.reason === 'mana') {
            emit('empty', { id: result.weapon.id, name: result.weapon.name });
            applySelection();
            emitState();
        }
        return false;
    }
    const definition = result.weapon;
    recoil = definition.id === 'emberstaff' ? 1.6 : 1;
    flashTime = 0.09;
    const forward = cameraEntity.forward.clone().normalize();
    const right = cameraEntity.right.clone().normalize();
    const origin = cameraEntity.getPosition().clone();
    // Start at the eye, so the crosshair is truthful and a muzzle cannot emerge through a wall.
    for (const spread of definition.id === 'crossbow' ? [-0.035, 0, 0.035] : [0]) {
        const direction = forward.clone().add(right.clone().mulScalar(spread)).normalize();
        const entity = new pc.Entity(`${definition.id}-projectile`);
        entity.addComponent('render', { type: definition.id === 'crossbow' ? 'box' : 'sphere', material: projectileMaterials.get(definition.id) });
        entity.setPosition(origin);
        if (definition.id === 'crossbow') {
            entity.setLocalScale(0.055, 0.055, 0.6);
            entity.lookAt(origin.clone().add(direction));
        } else {
            const size = definition.radius * 1.4;
            entity.setLocalScale(size, size, size);
        }
        app.root.addChild(entity);
        projectiles.add({ entity, direction, definition, travelled: 0, trailTime: 0 });
    }
    emitState();
    emit('shot', { id: definition.id, damage: definition.damage, cost: definition.cost });
    return true;
}

function updateProjectiles(dt) {
    for (const active of projectiles) {
        const { entity, direction, definition } = active;
        const from = entity.getPosition().clone();
        const distance = Math.min(definition.speed * dt, definition.range - active.travelled);
        const to = from.clone().add(direction.clone().mulScalar(distance));
        const wall = traceDungeonSegment(from, to, dungeon, definition.radius);
        // Enemy collision receives only the open segment before the first wall.
        const safeTo = wall ? pointOnSegment(from, to, Math.max(0, wall.t - 0.0001)) : to;
        const hit = (!wall || wall.t > 0) ? enemySystem?.hitSegment?.(from, safeTo, definition.damage, definition.radius) : null;
        if (hit || wall) {
            impact(hit?.position || wall?.position || safeTo, definition);
            if (hit) emit('hit', { ...hit, weapon: definition.id });
            entity.destroy();
            projectiles.delete(active);
            continue;
        }
        entity.setPosition(to);
        active.travelled += distance;
        active.trailTime += dt;
        if (active.trailTime >= 0.035) {
            active.trailTime = 0;
            spawnEffect(to, definition, definition.radius * 0.8, definition.id === 'emberstaff' ? 0.22 : 0.1);
        }
        if (active.travelled >= definition.range - 0.0001) {
            entity.destroy();
            projectiles.delete(active);
        }
    }
}

function updateWeapon(dt) {
    if (!app || app.gamePaused || !weapon) return;
    dt = Math.min(Math.max(dt, 0), 0.05);
    arsenal.tick(dt);
    poseWeapon(dt);
    updateProjectiles(dt);
    for (const effect of effects) {
        effect.age += dt;
        if (effect.age >= effect.life) {
            effect.entity.destroy();
            effects.delete(effect);
            continue;
        }
        if (effect.velocity) effect.entity.translate(effect.velocity.clone().mulScalar(dt));
        const progress = effect.age / effect.life;
        const size = effect.size * (effect.expand ? 1 + progress * 3 : 1 - progress);
        effect.entity.setLocalScale(size, size, size);
    }
}

function emitState() { emit('state-change', arsenal.getState()); }
function getState() { return arsenal.getState(); }
function setMoving(value) { moving = Boolean(value); }
function setEnemySystem(component) { enemySystem = component; }
function setCollisionGrid(grid, width, height, tileSize, wallHeight = 3.3) {
    dungeon = { grid: grid || [], width, height, tileSize, wallHeight };
}
function selectWeapon(idOrSlot) {
    if (!arsenal.select(idOrSlot)) return false;
    applySelection();
    emitState();
    return true;
}
function cycleWeapon(direction = 1) {
    if (!arsenal.cycle(direction)) return false;
    applySelection();
    emitState();
    return true;
}
function addMana(amount) {
    const added = arsenal.addMana(amount);
    if (added) emitState();
    return added;
}
function unlockWeapon(id) {
    if (!arsenal.unlock(id)) return false;
    applySelection();
    emitState();
    return true;
}
function restoreState(state) {
    arsenal.restore(state);
    applySelection();
    emitState();
}

function cleanup() {
    generation += 1;
    app?.off('update', updateWeapon);
    cancelLoads.forEach((cancel) => cancel());
    cancelLoads.clear();
    weapon?.destroy();
    flash?.destroy();
    flashLight?.destroy();
    projectiles.forEach(({ entity }) => entity.destroy());
    effects.forEach(({ entity }) => entity.destroy());
    projectiles.clear();
    effects.clear();
    if (overlayLayer) {
        if (camera?.camera) camera.camera.layers = camera.camera.layers.filter((id) => id !== overlayLayer.id);
        app?.scene?.layers.remove(overlayLayer);
    }
    ownedMaterials.forEach((material) => material.destroy());
    ownedTextures.forEach((texture) => texture.destroy());
    loadedAssets.forEach((asset) => { asset.unload(); app?.assets.remove(asset); });
    ownedMaterials.clear();
    ownedTextures.clear();
    loadedAssets.clear();
    textures.clear();
    spriteMaterials.clear();
    projectileMaterials.clear();
    weapon = null;
    flash = null;
    flashLight = null;
    overlayLayer = null;
    camera = null;
    enemySystem = null;
    app = null;
    arsenal = new Arsenal();
    dungeon = { grid: [], width: 0, height: 0, tileSize: 1, wallHeight: 3.3 };
    bobTime = recoil = switchDip = flashTime = movementBlend = 0;
    moving = false;
}

onBeforeUnmount(cleanup);
defineExpose({ setupWeapon, cleanup, setMoving, fire, setCollisionGrid, setEnemySystem, selectWeapon, cycleWeapon, addMana, unlockWeapon, getState, restoreState });
</script>

<template>
    <div hidden />
</template>
