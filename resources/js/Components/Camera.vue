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

function tileAtWorldPosition(x, z, collisionGrid, dungeonWidth, dungeonHeight, tileSize) {
    const tileX = Math.floor(x / tileSize + dungeonWidth / 2 + 0.5);
    const tileY = Math.floor(z / tileSize + dungeonHeight / 2 + 0.5);
    return { x: tileX, y: tileY, cell: collisionGrid[tileY]?.[tileX] };
}

function rampSidePenetration(x, z, collisionGrid, dungeonWidth, dungeonHeight, tileSize) {
    let penetration = 0;
    const wallThickness = 0.28;
    const innerWallEdge = tileSize / 2 - wallThickness;

    for (let tileY = 0; tileY < collisionGrid.length; tileY += 1) {
        for (let tileX = 0; tileX < (collisionGrid[tileY]?.length || 0); tileX += 1) {
            const cell = collisionGrid[tileY][tileX];
            if (cell?.type !== 'vertical-corridor') {
                continue;
            }

            const centerX = (tileX - dungeonWidth / 2) * tileSize;
            const centerZ = (tileY - dungeonHeight / 2) * tileSize;
            const relativeX = x - centerX;
            const relativeZ = z - centerZ;
            const along = relativeX * cell.direction.x + relativeZ * cell.direction.y;
            const across = Math.abs(relativeX * -cell.direction.y + relativeZ * cell.direction.x);

            if (Math.abs(along) > tileSize / 2 + props.playerRadius) {
                continue;
            }

            const overlap = across + props.playerRadius - innerWallEdge;
            if (overlap > 0 && across < tileSize / 2 + wallThickness + props.playerRadius) {
                penetration = Math.max(penetration, overlap);
            }
        }
    }

    return penetration;
}

function isWalkableWorldPosition(x, z, collisionGrid, dungeonWidth, dungeonHeight, tileSize, fromTile, fromPosition) {
    const nextRampPenetration = rampSidePenetration(
        x,
        z,
        collisionGrid,
        dungeonWidth,
        dungeonHeight,
        tileSize,
    );
    const currentRampPenetration = fromPosition
        ? rampSidePenetration(
            fromPosition.x,
            fromPosition.z,
            collisionGrid,
            dungeonWidth,
            dungeonHeight,
            tileSize,
        )
        : 0;

    if (nextRampPenetration > 0 && nextRampPenetration >= currentRampPenetration) {
        return false;
    }

    const samples = [
        { x, z },
        { x: x - props.playerRadius, z: z - props.playerRadius },
        { x: x + props.playerRadius, z: z - props.playerRadius },
        { x: x - props.playerRadius, z: z + props.playerRadius },
        { x: x + props.playerRadius, z: z + props.playerRadius },
    ];

    return samples.every((sample) => {
        const tile = tileAtWorldPosition(sample.x, sample.z, collisionGrid, dungeonWidth, dungeonHeight, tileSize);
        const { cell } = tile;
        if (!cell?.walkable) {
            return false;
        }

        if (!fromTile?.cell) {
            return true;
        }

        const corridorCell = fromTile.cell.type === 'vertical-corridor'
            ? fromTile.cell
            : cell.type === 'vertical-corridor'
                ? cell
                : null;

        if (corridorCell) {
            const deltaX = tile.x - fromTile.x;
            const deltaY = tile.y - fromTile.y;
            const lateralDelta = deltaX * corridorCell.direction.y - deltaY * corridorCell.direction.x;
            if (lateralDelta !== 0) {
                return false;
            }
        }

        return cell.floor === fromTile.cell.floor ||
            cell.type === 'vertical-corridor' ||
            fromTile.cell.type === 'vertical-corridor';
    });
}

function surfaceElevation(x, z, collisionGrid, dungeonWidth, dungeonHeight, tileSize) {
    const tile = tileAtWorldPosition(x, z, collisionGrid, dungeonWidth, dungeonHeight, tileSize);
    const cell = tile.cell;
    if (!cell?.walkable) {
        return null;
    }

    if (cell.type !== 'vertical-corridor') {
        return cell.elevation;
    }

    const centerX = (tile.x - dungeonWidth / 2) * tileSize;
    const centerZ = (tile.y - dungeonHeight / 2) * tileSize;
    const alongSlope = (x - centerX) * cell.direction.x + (z - centerZ) * cell.direction.y;
    return cell.elevation + Math.tan(cell.slope * pc.math.DEG_TO_RAD) * alongSlope;
}

function moveWithCollision(deltaX, deltaZ, collisionGrid, dungeonWidth, dungeonHeight, tileSize) {
    if (!camera) {
        return null;
    }

    const position = camera.getLocalPosition();
    const current = tileAtWorldPosition(position.x, position.z, collisionGrid, dungeonWidth, dungeonHeight, tileSize);
    let nextX = position.x + deltaX;
    let nextZ = position.z;

    if (!isWalkableWorldPosition(
        nextX,
        nextZ,
        collisionGrid,
        dungeonWidth,
        dungeonHeight,
        tileSize,
        current,
        position,
    )) {
        nextX = position.x;
    }

    nextZ = position.z + deltaZ;

    const afterX = tileAtWorldPosition(nextX, nextZ, collisionGrid, dungeonWidth, dungeonHeight, tileSize);
    if (!isWalkableWorldPosition(
        nextX,
        nextZ,
        collisionGrid,
        dungeonWidth,
        dungeonHeight,
        tileSize,
        afterX.cell ? afterX : current,
        { x: nextX, z: position.z },
    )) {
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

function getCamera() {
    return camera;
}

function getRotation() {
    return { yaw, pitch };
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
    dispose,
});
</script>

<template>
    <div hidden />
</template>
