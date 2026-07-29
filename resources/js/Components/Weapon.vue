<script setup>
import * as pc from 'playcanvas';

let app = null;
let weapon = null;
let bobTime = 0;
let basePosition = null;
let moving = false;
let lastShotAt = -Infinity;
const shotCooldown = 120;
const projectiles = new Set();

function loadTexture(appInstance, url) {
    return new Promise((resolve, reject) => {
        const asset = new pc.Asset(`weapon-${url}`, 'texture', { url });
        asset.once('load', () => resolve(asset.resource));
        asset.once('error', () => reject(new Error(`Unable to load weapon asset: ${url}`)));
        appInstance.assets.add(asset);
        appInstance.assets.load(asset);
    });
}

async function setupWeapon(appInstance, camera, weaponAssets) {
    if (!weaponAssets?.length) {
        return;
    }

    app = appInstance;
    const texture = await loadTexture(app, weaponAssets[0].path_url);
    const material = new pc.StandardMaterial();
    material.diffuseMap = texture;
    material.opacityMap = texture;
    material.opacityMapChannel = 'a';
    material.diffuse.set(1, 1, 1);
    material.alphaTest = 0.05;
    material.blendType = pc.BLEND_NORMAL;
    material.depthWrite = false;
    material.depthTest = false;
    material.cull = pc.CULLFACE_NONE;
    material.update();

    weapon = new pc.Entity('player-weapon');
    weapon.addComponent('render', { type: 'plane', material });
    const height = 1.25;
    weapon.setLocalScale(height * texture.width / texture.height, 1, height);
    weapon.setLocalEulerAngles(90, 0, 0);
    basePosition = { x: 0, y: -0.45, z: -1.1 };
    weapon.setLocalPosition(basePosition.x, basePosition.y, basePosition.z);
    camera.addChild(weapon);
    app.on('update', updateWeapon);
}

function updateWeapon(dt) {
    if (!weapon || !basePosition) {
        return;
    }

    if (moving) {
        bobTime += dt;
    }
    const bobAmount = moving ? 1 : 0;
    weapon.setLocalPosition(
        basePosition.x,
        basePosition.y + Math.sin(bobTime * 7) * 0.035 * bobAmount,
        basePosition.z + Math.abs(Math.cos(bobTime * 7)) * 0.025 * bobAmount,
    );
}

function setMoving(value) {
    moving = Boolean(value);
}

function makeMaterial(color, emissive = color) {
    const material = new pc.StandardMaterial();
    material.diffuse.set(color[0], color[1], color[2]);
    material.emissive.set(emissive[0], emissive[1], emissive[2]);
    material.emissiveIntensity = 3;
    material.update();
    return material;
}

function impact(position, color) {
    const burst = new pc.Entity('projectile-impact');
    burst.addComponent('render', { type: 'sphere', material: makeMaterial(color) });
    burst.setPosition(position);
    burst.setLocalScale(0.08, 0.08, 0.08);
    app.root.addChild(burst);
    let age = 0;
    const update = (dt) => {
        age += dt;
        burst.setLocalScale(0.08 + age * 1.5, 0.08 + age * 1.5, 0.08 + age * 1.5);
        if (age > 0.16) {
            app.off('update', update);
            burst.destroy();
        }
    };
    app.on('update', update);
}

function fire(camera) {
    const now = performance.now();
    if (!app || !weapon || !camera || now - lastShotAt < shotCooldown) return false;
    lastShotAt = now;

    const position = weapon.getPosition().clone();
    const direction = camera.forward.clone().normalize();
    const color = [0.35 + Math.random() * 0.65, 0.12 + Math.random() * 0.35, 1];
    const projectile = new pc.Entity('projectile');
    projectile.addComponent('render', { type: 'sphere', material: makeMaterial(color) });
    projectile.setPosition(position);
    projectile.setLocalScale(0.09, 0.09, 0.09);
    app.root.addChild(projectile);
    projectiles.add(projectile);
    let age = 0;
    const speed = 24;
    const update = (dt) => {
        age += dt;
        const step = direction.clone().mulScalar(speed * dt);
        const next = projectile.getPosition().clone().add(step);
        const cell = collisionGridAt(next.x, next.z);
        // The projectile disappears on the first solid dungeon cell or after its range.
        if (!cell?.walkable || age > 3) {
            impact(projectile.getPosition(), color);
            app.off('update', update);
            projectiles.delete(projectile);
            projectile.destroy();
            return;
        }
        projectile.setPosition(next);
    };
    app.on('update', update);
    return true;
}

let collisionGrid = [];
let dungeonWidth = 0;
let dungeonHeight = 0;
let tileSize = 1;

function setCollisionGrid(grid, width, height, size) {
    collisionGrid = grid || [];
    dungeonWidth = width;
    dungeonHeight = height;
    tileSize = size;
}

function collisionGridAt(x, z) {
    const tx = Math.floor(x / tileSize + dungeonWidth / 2 + 0.5);
    const ty = Math.floor(z / tileSize + dungeonHeight / 2 + 0.5);
    return collisionGrid[ty]?.[tx];
}

function cleanup() {
    app?.off('update', updateWeapon);
    weapon?.destroy?.();
    projectiles.forEach((projectile) => projectile.destroy());
    projectiles.clear();
    weapon = null;
}

defineExpose({ setupWeapon, cleanup, setMoving, fire, setCollisionGrid });
</script>

<template>
    <div hidden />
</template>
