<script setup>
import * as pc from 'playcanvas';

let app = null;
let weapon = null;
let bobTime = 0;
let basePosition = null;
let moving = false;

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

function cleanup() {
    app?.off('update', updateWeapon);
    weapon?.destroy?.();
    weapon = null;
}

defineExpose({ setupWeapon, cleanup, setMoving });
</script>

<template>
    <div hidden />
</template>
