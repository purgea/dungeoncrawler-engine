<script setup>
import { onBeforeUnmount } from 'vue';
import * as pc from 'playcanvas';
import { FiniteStateMachine } from '../ai/FiniteStateMachine';
import {
    canTraverseTiles,
    getRampCells,
    isWalkableWorldPosition,
    surfaceElevation as getSurfaceElevation,
    tileAtWorldPosition,
} from '../ai/GridCollision';
import { GridPathfinder } from '../ai/GridPathfinder';
import { colorFromRgb, falloffModeFromName } from '../lighting';

const emit = defineEmits(['state-change', 'attack']);

const enemyConfig = {
    sightRange: 34,
    attackRange: 2.3,
    moveSpeed: 4.8,
    attackSpeed: 0.85,
    damage: 8,
    rangedDecisionDistance: 8,
    projectileSpeed: 18,
    projectileDamage: 5,
    projectileCooldown: 1.1,
    radius: 0.48,
    height: 1.65,
};

let app = null;
let player = null;
let lighting = null;
let collisionGrid = [];
let rampCells = [];
let dungeonWidth = 0;
let dungeonHeight = 0;
let tileSize = 1;
let pathfinder = null;
let path = [];
let pathIndex = 0;
let pathTargetKey = null;
let pathRepathTimer = 0;
let enemy = null;
let sprite = null;
let spriteMaterials = [];
let frameTextures = [];
let animationTime = 0;
let animationFrame = 0;
let attackCooldown = 0;
let rangedDecisionCooldown = 0;
let lastRangedDecisionKey = null;
const projectiles = new Set();
let stateMachine = null;

function randomBetween(min, max) {
    return min + Math.random() * (max - min);
}

function createFrameCanvas(frame, hue) {
    const canvas = document.createElement('canvas');
    canvas.width = 128;
    canvas.height = 128;
    const ctx = canvas.getContext('2d');
    const bob = [0, 3, 1, -2][frame];
    const squash = [1, 0.96, 0.92, 0.96][frame];

    ctx.clearRect(0, 0, 128, 128);
    ctx.save();
    ctx.translate(64, 72 + bob);
    ctx.scale(1, squash);

    ctx.shadowColor = `hsla(${hue}, 100%, 60%, ${lighting.materials.enemy.sprite_shadow_alpha})`;
    ctx.shadowBlur = lighting.materials.enemy.sprite_shadow_blur;
    ctx.fillStyle = `hsl(${hue} 62% 42%)`;
    ctx.beginPath();
    ctx.moveTo(-31, 36);
    ctx.quadraticCurveTo(-36, -13, -24, -37);
    ctx.quadraticCurveTo(0, -53, 24, -37);
    ctx.quadraticCurveTo(36, -13, 31, 36);
    ctx.quadraticCurveTo(0, 52, -31, 36);
    ctx.fill();

    ctx.shadowBlur = 0;
    ctx.fillStyle = `hsl(${hue} 42% 25%)`;
    ctx.beginPath();
    ctx.moveTo(-23, -34);
    ctx.lineTo(-18, -58);
    ctx.lineTo(-4, -40);
    ctx.moveTo(23, -34);
    ctx.lineTo(18, -58);
    ctx.lineTo(4, -40);
    ctx.fill();

    ctx.fillStyle = '#e6d8a0';
    ctx.beginPath();
    ctx.ellipse(-13, -15, 8, 11, -0.1, 0, Math.PI * 2);
    ctx.ellipse(13, -15, 8, 11, 0.1, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = '#24131a';
    ctx.beginPath();
    ctx.ellipse(-11 + frame % 2, -13, 3, 6, 0, 0, Math.PI * 2);
    ctx.ellipse(11 + frame % 2, -13, 3, 6, 0, 0, Math.PI * 2);
    ctx.fill();

    ctx.strokeStyle = '#24131a';
    ctx.lineWidth = 4;
    ctx.lineCap = 'round';
    ctx.beginPath();
    ctx.arc(0, 7, 13, 0.15, Math.PI - 0.15);
    ctx.stroke();
    ctx.restore();

    return canvas;
}

function createProceduralFrames() {
    const hue = Math.floor(randomBetween(0, 360));
    return [0, 1, 2, 3].map((frame) => createFrameCanvas(frame, hue));
}

function createMaterial(texture) {
    const material = new pc.StandardMaterial();
    material.diffuseMap = texture;
    material.opacityMap = texture;
    material.opacityMapChannel = 'a';
    material.diffuse.set(1, 1, 1);
    material.emissive.set(...lighting.materials.enemy.emissive);
    material.emissiveIntensity = lighting.materials.enemy.emissive_intensity;
    material.alphaTest = 0.05;
    material.blendType = pc.BLEND_NORMAL;
    material.depthWrite = false;
    material.cull = pc.CULLFACE_NONE;
    material.update();
    return material;
}

function createProjectileMaterial() {
    const material = new pc.StandardMaterial();
    material.diffuse.set(0.45, 0.14, 1);
    material.emissive.set(...lighting.materials.projectile.emissive);
    material.emissiveIntensity = lighting.materials.projectile.emissive_intensity;
    material.update();
    return material;
}

function cellAtWorldPosition(x, z) {
    return tileAtWorldPosition(x, z, collisionGrid, dungeonWidth, dungeonHeight, tileSize);
}

function isWalkable(x, z, fromPosition) {
    const fromTile = fromPosition ? cellAtWorldPosition(fromPosition.x, fromPosition.z) : null;

    return isWalkableWorldPosition({
        x,
        z,
        grid: collisionGrid,
        width: dungeonWidth,
        height: dungeonHeight,
        tileSize,
        radius: enemyConfig.radius,
        ramps: rampCells,
        fromTile,
        fromPosition,
    });
}

function hasLineOfSight(from, to) {
    const distance = Math.hypot(to.x - from.x, to.z - from.z);
    if (distance > enemyConfig.sightRange) {
        return false;
    }

    const samples = Math.max(2, Math.ceil(distance / Math.max(tileSize * 0.35, 0.5)));
    const fromTile = cellAtWorldPosition(from.x, from.z);
    const toTile = cellAtWorldPosition(to.x, to.z);
    if (!fromTile.cell?.walkable || !toTile.cell?.walkable) {
        return false;
    }

    let previousTile = fromTile;
    for (let index = 1; index < samples; index += 1) {
        const progress = index / samples;
        const tile = cellAtWorldPosition(
            from.x + (to.x - from.x) * progress,
            from.z + (to.z - from.z) * progress,
        );
        if (!tile.cell?.walkable) {
            return false;
        }
        if ((tile.x !== previousTile.x || tile.y !== previousTile.y) &&
            !canTraverseTiles(collisionGrid, previousTile, tile)) {
            return false;
        }
        previousTile = tile;
    }

    return (toTile.x === previousTile.x && toTile.y === previousTile.y) ||
        canTraverseTiles(collisionGrid, previousTile, toTile);
}

function playerPosition() {
    const cameraPosition = player?.getCamera?.()?.getLocalPosition?.();
    return cameraPosition ? { x: cameraPosition.x, z: cameraPosition.z } : null;
}

function distanceToPlayer(position = enemy?.getPosition?.()) {
    const target = playerPosition();
    return target && position ? Math.hypot(target.x - position.x, target.z - position.z) : Infinity;
}

function canSeePlayer() {
    const target = playerPosition();
    return Boolean(target && enemy && hasLineOfSight(enemy.getPosition(), target));
}

function tileKey(tile) {
    return tile ? `${tile.x}:${tile.y}` : null;
}

function worldPositionForTile(tile) {
    return {
        x: (tile.x - dungeonWidth / 2) * tileSize,
        z: (tile.y - dungeonHeight / 2) * tileSize,
    };
}

function refreshPath(force = false) {
    const enemyPosition = enemy.getPosition();
    const enemyTile = cellAtWorldPosition(enemyPosition.x, enemyPosition.z);
    const target = playerPosition();
    const playerTile = target ? cellAtWorldPosition(target.x, target.z) : null;
    const nextTargetKey = tileKey(playerTile);

    if (!playerTile?.cell || !enemyTile?.cell || !pathfinder) {
        path = [];
        pathIndex = 0;
        pathTargetKey = null;
        return;
    }

    if (!force && pathTargetKey === nextTargetKey && pathRepathTimer > 0) {
        return;
    }

    path = pathfinder.findPath(enemyTile, playerTile);
    pathIndex = path.length > 1 ? 1 : 0;
    pathTargetKey = nextTargetKey;
    pathRepathTimer = 0.25;
}

function moveTowardPlayer(dt) {
    const target = playerPosition();
    const position = enemy?.getPosition();
    if (!target || !position) {
        return;
    }

    pathRepathTimer = Math.max(0, pathRepathTimer - dt);
    refreshPath();
    if (!path.length) {
        return;
    }

    if (considerRangedAction(dt)) {
        return;
    }

    const isFinalWaypoint = pathIndex >= path.length - 1;
    const waypoint = isFinalWaypoint ? target : worldPositionForTile(path[pathIndex]);
    const deltaX = waypoint.x - position.x;
    const deltaZ = waypoint.z - position.z;
    const distance = Math.hypot(deltaX, deltaZ);
    if (distance < 0.001) {
        if (!isFinalWaypoint) {
            pathIndex += 1;
        }
        return;
    }

    const step = Math.min(enemyConfig.moveSpeed * dt, distance);
    const nextX = position.x + deltaX / distance * step;
    const nextZ = position.z + deltaZ / distance * step;
    let movedX = position.x;
    let movedZ = position.z;

    if (isWalkable(nextX, position.z, position)) {
        movedX = nextX;
    }
    if (isWalkable(movedX, nextZ, { x: movedX, z: position.z })) {
        movedZ = nextZ;
    }

    const movedDistance = Math.hypot(movedX - position.x, movedZ - position.z);
    if (movedDistance < 0.001) {
        // A stale waypoint or dynamic obstruction should cause a fresh search.
        pathRepathTimer = 0;
        refreshPath(true);
    } else if (!isFinalWaypoint && Math.hypot(waypoint.x - movedX, waypoint.z - movedZ) < 0.12) {
        pathIndex += 1;
    }

    const elevation = getSurfaceElevation(
        movedX,
        movedZ,
        collisionGrid,
        dungeonWidth,
        dungeonHeight,
        tileSize,
    );
    enemy.setPosition(movedX, elevation ?? position.y, movedZ);
    enemy.lookAt(target.x, enemy.getPosition().y, target.z);
}

function shootProjectile() {
    const target = playerPosition();
    const cameraPosition = player?.getCamera?.()?.getLocalPosition?.();
    const origin = enemy?.getPosition?.()?.clone();
    if (!target || !origin) {
        return false;
    }

    origin.y += 1.05;
    const direction = new pc.Vec3(
        target.x - origin.x,
        (cameraPosition?.y ?? origin.y) - origin.y,
        target.z - origin.z,
    ).normalize();
    const projectile = new pc.Entity('enemy-projectile');
    projectile.addComponent('render', {
        type: 'sphere',
        material: createProjectileMaterial(),
    });
    projectile.setPosition(origin);
    projectile.setLocalScale(0.09, 0.09, 0.09);
    app.root.addChild(projectile);
    projectiles.add({ projectile, direction, age: 0 });
    emit('attack', { damage: enemyConfig.projectileDamage, ranged: true });

    return true;
}

function updateProjectiles(dt) {
    for (const active of projectiles) {
        active.age += dt;
        const step = active.direction.clone().mulScalar(enemyConfig.projectileSpeed * dt);
        const previous = active.projectile.getPosition().clone();
        const next = previous.clone().add(step);
        const target = playerPosition();
        const hitPlayer = target && Math.hypot(next.x - target.x, next.z - target.z) < 0.55;
        const previousTile = cellAtWorldPosition(previous.x, previous.z);
        const nextTile = cellAtWorldPosition(next.x, next.z);
        const crossedWall = (previousTile.x !== nextTile.x || previousTile.y !== nextTile.y) &&
            !canTraverseTiles(collisionGrid, previousTile, nextTile);
        const hitWall = !nextTile.cell?.walkable || crossedWall;

        if (hitPlayer) {
            player?.takeDamage?.(enemyConfig.projectileDamage);
            active.projectile.destroy();
            projectiles.delete(active);
            continue;
        }

        if (hitWall || active.age > 3) {
            active.projectile.destroy();
            projectiles.delete(active);
            continue;
        }

        active.projectile.setPosition(next);
    }
}

function considerRangedAction(dt) {
    rangedDecisionCooldown = Math.max(0, rangedDecisionCooldown - dt);
    if (rangedDecisionCooldown > 0) {
        return true;
    }

    const position = enemy?.getPosition?.();
    const target = playerPosition();
    const waypoint = path[pathIndex];
    if (!position || !target || !waypoint) {
        return false;
    }

    const currentTile = cellAtWorldPosition(position.x, position.z);
    const decisionKey = `${tileKey(currentTile)}>${tileKey(waypoint)}:${pathTargetKey}`;
    if (decisionKey === lastRangedDecisionKey) {
        return false;
    }
    lastRangedDecisionKey = decisionKey;

    const distance = Math.hypot(target.x - position.x, target.z - position.z);
    if (distance <= enemyConfig.rangedDecisionDistance || !canSeePlayer()) {
        return false;
    }

    if (Math.random() >= 0.2 || !shootProjectile()) {
        return false;
    }

    // A shot consumes this grid opportunity. Re-evaluate after the cooldown.
    lastRangedDecisionKey = null;
    rangedDecisionCooldown = enemyConfig.projectileCooldown;
    return true;
}

function setAnimation(name, dt) {
    animationTime += dt;
    const frameDuration = name === 'walk' ? 0.12 : name === 'attack' ? 0.1 : 0.28;
    const nextFrame = Math.floor(animationTime / frameDuration) % frameTextures.length;
    if (nextFrame !== animationFrame) {
        animationFrame = nextFrame;
        spriteMaterials[animationFrame].diffuseMap = frameTextures[animationFrame];
        spriteMaterials[animationFrame].opacityMap = frameTextures[animationFrame];
        spriteMaterials[animationFrame].update();
        sprite.render.material = spriteMaterials[animationFrame];
    }
    sprite.setLocalScale(
        1.15,
        1 + Math.sin(animationTime * 8) * (name === 'idle' ? 0.025 : 0.04),
        enemyConfig.height,
    );
}

function attackPlayer() {
    if (attackCooldown > 0 || distanceToPlayer() > enemyConfig.attackRange || !canSeePlayer()) {
        return;
    }

    attackCooldown = 1 / enemyConfig.attackSpeed;
    player?.takeDamage?.(enemyConfig.damage);
    emit('attack', { damage: enemyConfig.damage });
}

function createStateMachine() {
    const context = {};
    stateMachine = new FiniteStateMachine({
        IDLE: {
            enter: () => emit('state-change', 'IDLE'),
            update: () => (canSeePlayer() ? 'CHASE' : null),
        },
        CHASE: {
            enter: () => emit('state-change', 'CHASE'),
            update: (stateContext, dt) => {
                if (!playerPosition() || distanceToPlayer() > enemyConfig.sightRange) {
                    return 'IDLE';
                }
                if (distanceToPlayer() <= enemyConfig.attackRange && canSeePlayer()) {
                    return 'ATTACK';
                }
                moveTowardPlayer(dt);
                setAnimation('walk', dt);
                return null;
            },
        },
        ATTACK: {
            enter: () => emit('state-change', 'ATTACK'),
            update: (stateContext, dt) => {
                attackCooldown = Math.max(0, attackCooldown - dt);
                if (!playerPosition() || distanceToPlayer() > enemyConfig.sightRange) {
                    return 'IDLE';
                }
                if (distanceToPlayer() > enemyConfig.attackRange * 1.15) {
                    return 'CHASE';
                }
                if (!canSeePlayer()) {
                    return 'CHASE';
                }
                enemy.lookAt(playerPosition().x, enemy.getPosition().y, playerPosition().z);
                attackPlayer();
                setAnimation('attack', dt);
                return null;
            },
        },
    }, 'IDLE', context);
}

function setupEnemy(appInstance, playerComponent, spawnPoint, dungeon) {
    app = appInstance;
    player = playerComponent;
    collisionGrid = dungeon.grid;
    rampCells = getRampCells(collisionGrid);
    dungeonWidth = dungeon.width;
    dungeonHeight = dungeon.height;
    tileSize = dungeon.tileSize;
    lighting = dungeon.lighting;
    pathfinder = new GridPathfinder(collisionGrid, dungeonWidth, dungeonHeight);
    path = [];
    pathIndex = 0;
    pathTargetKey = null;
    pathRepathTimer = 0;
    rangedDecisionCooldown = 0;
    lastRangedDecisionKey = null;
    frameTextures = createProceduralFrames().map((canvas, index) => {
        const texture = new pc.Texture(app.graphicsDevice, {
            width: canvas.width,
            height: canvas.height,
            format: pc.PIXELFORMAT_R8_G8_B8_A8,
            mipmaps: true,
        });
        texture.addressU = pc.ADDRESS_CLAMP_TO_EDGE;
        texture.addressV = pc.ADDRESS_CLAMP_TO_EDGE;
        texture.minFilter = pc.FILTER_LINEAR_MIPMAP_LINEAR;
        texture.magFilter = pc.FILTER_LINEAR;
        texture.setSource(canvas);
        return texture;
    });
    spriteMaterials = frameTextures.map(createMaterial);

    enemy = new pc.Entity('procedural-enemy');
    enemy.setPosition(spawnPoint.x, spawnPoint.y, spawnPoint.z);

    sprite = new pc.Entity('procedural-enemy-sprite');
    sprite.addComponent('render', { type: 'plane', material: spriteMaterials[0] });
    sprite.setLocalScale(1.15, 1, enemyConfig.height);
    sprite.setLocalEulerAngles(90, 0, 0);
    sprite.setLocalPosition(0, enemyConfig.height / 2, 0);
    enemy.addChild(sprite);

    const light = new pc.Entity('procedural-enemy-light');
    const enemyLightConfig = lighting.enemy.light;
    light.addComponent('light', {
        type: 'omni',
        color: colorFromRgb(enemyLightConfig.color),
        intensity: enemyLightConfig.intensity,
        range: Math.max(tileSize * enemyLightConfig.range_tiles, 1),
        falloffMode: falloffModeFromName(enemyLightConfig.falloff),
        castShadows: enemyLightConfig.cast_shadows,
    });
    light.setLocalPosition(0, 1.05, 0);
    enemy.addChild(light);

    app.root.addChild(enemy);
    createStateMachine();
    app.on('update', updateEnemy);
}

function updateEnemy(dt) {
    if (!stateMachine || !enemy) {
        return;
    }

    updateProjectiles(dt);
    if (stateMachine.state === 'IDLE') {
        attackCooldown = Math.max(0, attackCooldown - dt);
        setAnimation('idle', dt);
    }
    stateMachine.update(dt);
}

function getState() {
    return stateMachine?.state ?? 'IDLE';
}

function cleanup() {
    app?.off('update', updateEnemy);
    enemy?.destroy?.();
    projectiles.forEach(({ projectile }) => projectile.destroy());
    projectiles.clear();
    frameTextures.forEach((texture) => texture.destroy?.());
    enemy = null;
    sprite = null;
    spriteMaterials = [];
    frameTextures = [];
    collisionGrid = [];
    rampCells = [];
    stateMachine = null;
    pathfinder = null;
    path = [];
    pathIndex = 0;
    pathTargetKey = null;
    pathRepathTimer = 0;
    rangedDecisionCooldown = 0;
    lastRangedDecisionKey = null;
    app = null;
    player = null;
    lighting = null;
}

onBeforeUnmount(cleanup);

defineExpose({ setupEnemy, getState, cleanup });
</script>

<template>
    <div hidden />
</template>
