<script setup>
import * as pc from 'playcanvas';

const props = defineProps({
    playerRadius: {
        type: Number,
        required: true,
    },
});

let camera = null;
let yaw = 0;
let pitch = 0;

function setupCamera(appInstance, spawnPoint) {
    camera = new pc.Entity('player-camera');
    camera.addComponent('camera', {
        clearColor: new pc.Color(0.02, 0.025, 0.02),
        fov: 72,
        nearClip: 0.05,
        farClip: 500,
    });
    camera.setLocalPosition(spawnPoint.x, spawnPoint.y, spawnPoint.z);
    appInstance.root.addChild(camera);

    return { camera, start: spawnPoint };
}

function setRotation(nextYaw, nextPitch) {
    yaw = nextYaw;
    pitch = nextPitch;
    camera?.setEulerAngles(pitch, yaw, 0);
}

function isWalkableWorldPosition(x, z, collisionGrid, dungeonWidth, dungeonHeight, tileSize, floor = 0) {
    const samples = [
        { x, z },
        { x: x - props.playerRadius, z: z - props.playerRadius },
        { x: x + props.playerRadius, z: z - props.playerRadius },
        { x: x - props.playerRadius, z: z + props.playerRadius },
        { x: x + props.playerRadius, z: z + props.playerRadius },
    ];

    return samples.every((sample) => {
        const tile = {
            x: Math.floor(sample.x / tileSize + dungeonWidth / 2 + 0.5),
            y: Math.floor(sample.z / tileSize + dungeonHeight / 2 + 0.5),
        };

        return collisionGrid[floor]?.[tile.y]?.[tile.x] === 1 || collisionGrid[floor]?.[tile.y]?.[tile.x] === 2;
    });
}

function moveWithCollision(deltaX, deltaZ, collisionGrid, dungeonWidth, dungeonHeight, tileSize, floor = 0) {
    if (!camera) {
        return;
    }

    const position = camera.getLocalPosition();
    let nextX = position.x + deltaX;
    let nextZ = position.z;

    if (!isWalkableWorldPosition(nextX, nextZ, collisionGrid, dungeonWidth, dungeonHeight, tileSize, floor)) {
        nextX = position.x;
    }

    nextZ = position.z + deltaZ;

    if (!isWalkableWorldPosition(nextX, nextZ, collisionGrid, dungeonWidth, dungeonHeight, tileSize, floor)) {
        nextZ = position.z;
    }

    camera.setLocalPosition(nextX, 1.55, nextZ);
}

function getCamera() {
    return camera;
}

function getRotation() {
    return { yaw, pitch };
}

function addLight(appInstance) {
    const light = new pc.Entity('player-light');
    light.addComponent('light', {
        type: 'omni',
        color: new pc.Color(1, 0.9, 0.72),
        intensity: 1.65,
        range: 18,
        castShadows: false,
    });
    light.setLocalPosition(0, 0.25, 0);
    camera?.addChild(light);
}

function dispose() {
    camera?.destroy?.();
    camera = null;
}

defineExpose({
    setupCamera,
    setRotation,
    moveWithCollision,
    getCamera,
    getRotation,
    addLight,
    dispose,
});
</script>

<template>
    <div hidden />
</template>
