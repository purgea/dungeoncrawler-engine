<script setup>
import { onBeforeUnmount } from 'vue';
import * as pc from 'playcanvas';
import { getRampCells, isWalkableWorldPosition, surfaceElevation, tileAtWorldPosition } from '../ai/GridCollision.js';
import { GridPathfinder } from '../ai/GridPathfinder.js';
import { pointOnSegment, traceDungeonSegment } from '../game/Combat.js';
import { ENEMY_TYPES, nearestEnemyHit, segmentBodyIntersection } from '../game/EnemyCombat.js';
import { createEnemyFrame } from '../game/EnemySprites.js';

const emit = defineEmits(['state-change', 'attack', 'hit', 'kill']);
let app = null;
let player = null;
let dungeon = null;
let ramps = [];
let pathfinder = null;
let elapsed = 0;
let killCount = 0;
let nextPathSearch = 0;
const enemies = [];
const speciesAssets = new Map();
const projectiles = new Set();
const particles = new Set();
const sharedMaterials = [];
let projectileMaterial = null;
let projectileHaloMaterial = null;
let bloodMaterial = null;
let shadowMaterial = null;

function solidMaterial(color, glow = 0, opacity = 1) {
    const material = new pc.StandardMaterial();
    material.diffuse.set(...color);
    material.emissive.set(...color);
    material.emissiveIntensity = glow;
    material.opacity = opacity;
    if (opacity < 1) {
        material.blendType = pc.BLEND_NORMAL;
        material.depthWrite = false;
    }
    material.update();
    sharedMaterials.push(material);
    return material;
}

function createSpriteMaterial(texture, effect) {
    const material = new pc.StandardMaterial();
    material.diffuseMap = texture;
    material.opacityMap = texture;
    material.opacityMapChannel = 'a';
    material.emissiveMap = effect === 'hit' ? null : texture;
    material.diffuse.set(0.65, 0.65, 0.65);
    material.emissive.set(...(effect === 'hit' ? [1, 0.42, 0.22] : effect === 'attack' ? [1, 0.8, 0.55] : [0.75, 0.75, 0.75]));
    material.emissiveIntensity = effect === 'hit' ? 2.8 : effect === 'attack' ? 1.7 : 0.85;
    material.alphaTest = 0.45;
    material.cull = pc.CULLFACE_NONE;
    material.depthWrite = true;
    material.update();
    return material;
}

function assetsFor(type) {
    if (speciesAssets.has(type)) return speciesAssets.get(type);
    const textures = [0, 1, 2, 3].map((frame) => {
        const canvas = createEnemyFrame(type, frame);
        const texture = new pc.Texture(app.graphicsDevice, {
            name: `enemy-${type}-${frame}`, width: 64, height: 96,
            format: pc.PIXELFORMAT_R8_G8_B8_A8, mipmaps: false,
            minFilter: pc.FILTER_NEAREST, magFilter: pc.FILTER_NEAREST,
            addressU: pc.ADDRESS_CLAMP_TO_EDGE, addressV: pc.ADDRESS_CLAMP_TO_EDGE,
        });
        texture.setSource(canvas);
        return texture;
    });
    const assets = {
        textures,
        normal: textures.map((texture) => createSpriteMaterial(texture, 'normal')),
        hit: textures.map((texture) => createSpriteMaterial(texture, 'hit')),
        attack: textures.map((texture) => createSpriteMaterial(texture, 'attack')),
    };
    speciesAssets.set(type, assets);
    return assets;
}

function bodyPosition(actor) {
    const position = actor.entity.getPosition();
    return { x: position.x, y: position.y, z: position.z };
}

function tileAt(x, z) {
    return tileAtWorldPosition(x, z, dungeon.grid, dungeon.width, dungeon.height, dungeon.tileSize);
}

function targetPosition() {
    const position = player?.getCamera?.()?.getPosition?.();
    return position ? { x: position.x, y: position.y, z: position.z } : null;
}

function chestPosition(actor) {
    const position = bodyPosition(actor);
    position.y += actor.config.height * 0.63;
    return position;
}

function canSee(actor, target) {
    const eye = chestPosition(actor);
    return Math.hypot(target.x - eye.x, target.z - eye.z) <= actor.config.sight &&
        traceDungeonSegment(eye, target, dungeon) === null;
}

function setState(actor, state) {
    if (actor.state === state) return;
    actor.state = state;
    emit('state-change', { id: actor.id, type: actor.type, state });
}

function createEnemy(placement, index) {
    const type = ENEMY_TYPES[placement.type] ? placement.type : 'imp';
    const config = ENEMY_TYPES[type];
    const assets = assetsFor(type);
    // Placements are map coordinates. The single-enemy compatibility entry
    // point marks world positions explicitly instead of guessing from values.
    const x = placement.world ? placement.x : (placement.x - dungeon.width / 2) * dungeon.tileSize;
    const z = placement.world ? placement.z : (placement.y - dungeon.height / 2) * dungeon.tileSize;
    const y = surfaceElevation(x, z, dungeon.grid, dungeon.width, dungeon.height, dungeon.tileSize);
    if (y === null) return;
    const entity = new pc.Entity(`enemy-${type}-${placement.id ?? index}`);
    entity.setPosition(x, y, z);
    const sprite = new pc.Entity('enemy-billboard');
    sprite.addComponent('render', { type: 'plane', material: assets.normal[0], castShadows: false });
    sprite.setLocalEulerAngles(90, 0, 0);
    sprite.setLocalScale(config.width, 1, config.height);
    sprite.setLocalPosition(0, config.height / 2, 0);
    entity.addChild(sprite);
    const shadow = new pc.Entity('enemy-shadow');
    shadow.addComponent('render', { type: 'cylinder', material: shadowMaterial, castShadows: false });
    shadow.setLocalScale(config.radius * 2.1, 0.012, config.radius * 1.4);
    shadow.setLocalPosition(0, 0.025, 0);
    entity.addChild(shadow);
    const charge = new pc.Entity('spell-charge');
    charge.addComponent('render', { type: 'sphere', material: projectileMaterial, castShadows: false });
    charge.setLocalPosition(0.35, config.height * 0.63, -0.2);
    charge.enabled = false;
    entity.addChild(charge);
    app.root.addChild(entity);
    enemies.push({
        id: placement.id ?? `enemy-${index}`, type, config, assets, entity, sprite, shadow, charge,
        health: config.health, state: 'IDLE', phase: index * 0.73,
        flash: 0, stagger: 0, cooldown: 0.5 + (index % 4) * 0.16, windup: 0,
        sightTimer: (index % 5) * 0.045, visible: false, awareness: 0, lastSeen: null,
        path: [], pathIndex: 0, repath: (index % 5) * 0.1, deathAge: 0,
    });
}

function setupEnemies(appInstance, playerComponent, placements, config) {
    cleanup();
    app = appInstance;
    player = playerComponent;
    dungeon = { ...config, wallHeight: config.wallHeight ?? 3.3 };
    ramps = getRampCells(dungeon.grid);
    pathfinder = new GridPathfinder(dungeon.grid, dungeon.width, dungeon.height);
    projectileMaterial = solidMaterial([0.2, 1, 0.67], 4);
    projectileHaloMaterial = solidMaterial([0.08, 0.7, 0.42], 2, 0.25);
    bloodMaterial = solidMaterial([0.6, 0.055, 0.025], 0.45);
    shadowMaterial = solidMaterial([0.025, 0.016, 0.018], 0, 0.45);
    (placements ?? []).forEach(createEnemy);
    app.on('update', updateEnemies);
    return getStats();
}

function setupEnemy(appInstance, playerComponent, spawnPoint, config) {
    return setupEnemies(appInstance, playerComponent, [{ ...spawnPoint, id: 'enemy-0', type: 'imp', world: true }], config);
}

function canWalk(actor, x, z, from) {
    return isWalkableWorldPosition({
        x, z, grid: dungeon.grid, width: dungeon.width, height: dungeon.height,
        tileSize: dungeon.tileSize, radius: actor.config.radius, ramps,
        fromTile: tileAt(from.x, from.z), fromPosition: from,
    });
}

function moveActor(actor, destination, dt, speedMultiplier = 1) {
    const position = bodyPosition(actor);
    let dx = destination.x - position.x;
    let dz = destination.z - position.z;
    const distance = Math.hypot(dx, dz);
    if (distance < 0.05) return;
    dx /= distance;
    dz /= distance;
    // A little separation avoids stacks of monsters occupying one hitbox.
    let avoidX = 0;
    let avoidZ = 0;
    for (const other of enemies) {
        if (other === actor || other.health <= 0) continue;
        const otherPosition = other.entity.getPosition();
        const separation = Math.hypot(position.x - otherPosition.x, position.z - otherPosition.z);
        const minimum = actor.config.radius + other.config.radius + 0.12;
        if (separation < minimum && separation > 0.01 && Math.abs(position.y - otherPosition.y) < 1) {
            avoidX += (position.x - otherPosition.x) / separation * (minimum - separation) * 1.4;
            avoidZ += (position.z - otherPosition.z) / separation * (minimum - separation) * 1.4;
        }
    }
    const step = Math.min(actor.config.speed * speedMultiplier * dt, distance);
    const nextX = position.x + (dx + avoidX) * step;
    const nextZ = position.z + (dz + avoidZ) * step;
    let x = position.x;
    let z = position.z;
    if (canWalk(actor, nextX, z, position)) x = nextX;
    if (canWalk(actor, x, nextZ, { x, z: position.z })) z = nextZ;
    const elevation = surfaceElevation(x, z, dungeon.grid, dungeon.width, dungeon.height, dungeon.tileSize);
    actor.entity.setPosition(x, elevation ?? position.y, z);
}

function chase(actor, target, dt) {
    actor.repath -= dt;
    if (actor.repath <= 0 && elapsed >= nextPathSearch) {
        const position = actor.entity.getPosition();
        actor.path = pathfinder.findPath(tileAt(position.x, position.z), tileAt(target.x, target.z));
        actor.pathIndex = actor.path.length > 1 ? 1 : 0;
        actor.repath = 0.8 + (actor.phase % 0.4);
        // At most one A* search per frame, even after a large group wakes up.
        nextPathSearch = elapsed + 0.001;
    }
    if (!actor.path.length) return;
    const waypoint = actor.path[actor.pathIndex];
    const final = actor.pathIndex >= actor.path.length - 1;
    const destination = final ? target : {
        x: (waypoint.x - dungeon.width / 2) * dungeon.tileSize,
        z: (waypoint.y - dungeon.height / 2) * dungeon.tileSize,
    };
    moveActor(actor, destination, dt);
    const position = actor.entity.getPosition();
    if (!final && Math.hypot(position.x - destination.x, position.z - destination.z) < 0.22) actor.pathIndex++;
}

function beginAttack(actor, target) {
    actor.windup = actor.config.windup;
    actor.aim = { ...target };
    actor.charge.enabled = actor.type === 'acolyte';
    setState(actor, 'ATTACK');
    emit('attack', { id: actor.id, type: actor.type, ranged: actor.type === 'acolyte', stage: 'windup', damage: actor.config.damage });
}

function releaseAttack(actor, target) {
    actor.charge.enabled = false;
    actor.cooldown = actor.config.cooldown;
    if (actor.type === 'acolyte') {
        shootProjectile(actor);
    } else {
        const position = actor.entity.getPosition();
        const inRange = Math.hypot(target.x - position.x, target.z - position.z) <= actor.config.range + 0.2;
        if (inRange && Math.abs(target.y - 1.55 - position.y) < 1.2 && canSee(actor, target)) {
            player?.takeDamage?.(actor.config.damage);
            emit('attack', { id: actor.id, type: actor.type, stage: 'hit', ranged: false, damage: actor.config.damage });
        }
    }
    setState(actor, 'CHASE');
}

function shootProjectile(actor) {
    const origin = chestPosition(actor);
    const direction = new pc.Vec3(actor.aim.x - origin.x, actor.aim.y - 0.2 - origin.y, actor.aim.z - origin.z).normalize();
    const entity = new pc.Entity('acolyte-soul-bolt');
    entity.addComponent('render', { type: 'sphere', material: projectileMaterial, castShadows: false });
    entity.setPosition(origin.x, origin.y, origin.z);
    entity.setLocalScale(0.3, 0.3, 0.3);
    const halo = new pc.Entity('soul-bolt-halo');
    halo.addComponent('render', { type: 'sphere', material: projectileHaloMaterial, castShadows: false });
    halo.setLocalScale(2, 2, 2);
    entity.addChild(halo);
    app.root.addChild(entity);
    projectiles.add({ entity, direction, age: 0, trailTimer: 0, speed: actor.config.projectileSpeed, damage: actor.config.damage, owner: actor.id });
    emit('attack', { id: actor.id, type: actor.type, stage: 'release', ranged: true, damage: actor.config.damage });
}

function burst(position, material, count = 6, power = 1) {
    for (let index = 0; index < count && particles.size < 96; index++) {
        const entity = new pc.Entity('enemy-impact');
        entity.addComponent('render', { type: 'box', material, castShadows: false });
        entity.setPosition(position.x, position.y, position.z);
        const size = (0.035 + Math.random() * 0.045) * power;
        entity.setLocalScale(size, size, size);
        app.root.addChild(entity);
        particles.add({ entity, age: 0, life: 0.28 + Math.random() * 0.28, size,
            velocity: new pc.Vec3((Math.random() - 0.5) * 3 * power, Math.random() * 2 * power, (Math.random() - 0.5) * 3 * power) });
    }
}

function updateProjectiles(dt, target) {
    const feet = { x: target.x, y: target.y - 1.55, z: target.z };
    for (const projectile of projectiles) {
        const old = projectile.entity.getPosition();
        const from = { x: old.x, y: old.y, z: old.z };
        const to = { x: from.x + projectile.direction.x * projectile.speed * dt,
            y: from.y + projectile.direction.y * projectile.speed * dt,
            z: from.z + projectile.direction.z * projectile.speed * dt };
        projectile.age += dt;
        projectile.trailTimer -= dt;
        const wall = traceDungeonSegment(from, to, dungeon, 0.15);
        const playerHit = segmentBodyIntersection(from, to, feet, 0.3, 1.72, 0.15);
        const hitPlayer = playerHit !== null && (!wall || playerHit < wall.t);
        if (hitPlayer || wall || projectile.age > 4.5) {
            if (hitPlayer) {
                player?.takeDamage?.(projectile.damage);
                emit('attack', { id: projectile.owner, type: 'acolyte', stage: 'hit', ranged: true, damage: projectile.damage });
            }
            burst(hitPlayer ? pointOnSegment(from, to, playerHit) : wall?.position ?? to, projectileMaterial, 5);
            projectile.entity.destroy();
            projectiles.delete(projectile);
        } else {
            projectile.entity.setPosition(to.x, to.y, to.z);
            const pulse = 0.3 + Math.sin(elapsed * 22) * 0.035;
            projectile.entity.setLocalScale(pulse, pulse, pulse);
            if (projectile.trailTimer <= 0) {
                burst(from, projectileMaterial, 1, 0.45);
                projectile.trailTimer = 0.06;
            }
        }
    }
}

function updateParticles(dt) {
    for (const particle of particles) {
        particle.age += dt;
        if (particle.age >= particle.life) {
            particle.entity.destroy();
            particles.delete(particle);
            continue;
        }
        particle.velocity.y -= dt * 5;
        const position = particle.entity.getPosition();
        particle.entity.setPosition(position.x + particle.velocity.x * dt, position.y + particle.velocity.y * dt, position.z + particle.velocity.z * dt);
        const scale = particle.size * (1 - particle.age / particle.life);
        particle.entity.setLocalScale(scale, scale, scale);
    }
}

function updateAppearance(actor, target, dt) {
    const position = actor.entity.getPosition();
    // Billboarding is independent of AI state, including idle and paused frames.
    actor.entity.lookAt(target.x, position.y, target.z);
    if (actor.health <= 0) {
        actor.deathAge += dt;
        const collapse = Math.min(1, actor.deathAge / 0.3);
        actor.sprite.setLocalScale(actor.config.width * (1 + collapse * 0.32), 1, actor.config.height * (1 - collapse * 0.91));
        actor.sprite.setLocalPosition(0, actor.config.height * (1 - collapse * 0.91) / 2, 0);
        actor.sprite.render.material = actor.assets.normal[0];
        return;
    }
    const moving = actor.state === 'CHASE' && actor.stagger <= 0;
    const frame = Math.floor((elapsed + actor.phase) / (moving ? 0.13 : 0.32)) % 4;
    const effect = actor.flash > 0 ? 'hit' : actor.windup > 0 ? 'attack' : 'normal';
    actor.sprite.render.material = actor.assets[effect][frame];
    const breathing = Math.sin(elapsed * (moving ? 12 : 3) + actor.phase) * (moving ? 0.022 : 0.012);
    actor.sprite.setLocalScale(actor.config.width, 1, actor.config.height * (1 + breathing));
    if (actor.charge.enabled) {
        const progress = 1 - actor.windup / actor.config.windup;
        const size = 0.15 + progress * 0.38 + Math.sin(elapsed * 24) * 0.03;
        actor.charge.setLocalScale(size, size, size);
    }
}

function updateActor(actor, target, dt) {
    actor.flash = Math.max(0, actor.flash - dt);
    actor.stagger = Math.max(0, actor.stagger - dt);
    actor.cooldown = Math.max(0, actor.cooldown - dt);
    actor.sightTimer -= dt;
    actor.awareness = Math.max(0, actor.awareness - dt);
    if (actor.sightTimer <= 0) {
        actor.sightTimer = 0.22 + (actor.phase % 0.08);
        actor.visible = canSee(actor, target);
        if (actor.visible) {
            actor.lastSeen = { ...target };
            actor.awareness = 7;
        }
    }
    if (actor.stagger > 0) return;
    if (actor.windup > 0) {
        actor.windup = Math.max(0, actor.windup - dt);
        if (actor.windup <= 0) releaseAttack(actor, target);
        return;
    }
    if (actor.awareness <= 0 || !actor.lastSeen) {
        setState(actor, 'IDLE');
        return;
    }
    const position = actor.entity.getPosition();
    const distance = Math.hypot(target.x - position.x, target.z - position.z);
    const sameHeight = Math.abs(target.y - 1.55 - position.y) < 1.2;
    if (actor.visible && distance <= actor.config.range && (actor.type === 'acolyte' || sameHeight) && actor.cooldown <= 0) {
        beginAttack(actor, target);
        return;
    }
    setState(actor, 'CHASE');
    if (actor.type === 'acolyte' && actor.visible && distance < 11) {
        if (distance < 4) moveActor(actor, { x: position.x + (position.x - target.x), z: position.z + (position.z - target.z) }, dt, 0.65);
        return;
    }
    if (actor.type !== 'acolyte' && actor.visible && distance < actor.config.range * 0.8 && sameHeight) return;
    chase(actor, actor.lastSeen, dt);
}

function updateEnemies(deltaTime) {
    const target = targetPosition();
    if (!app || !target) return;
    const paused = app.gamePaused || player?.getHealth?.() <= 0;
    const dt = paused ? 0 : Math.min(Math.max(deltaTime, 0), 0.06);
    elapsed += dt;
    if (!paused) {
        updateProjectiles(dt, target);
        updateParticles(dt);
    }
    for (const actor of enemies) {
        if (!paused && actor.health > 0 && player?.getHealth?.() > 0) updateActor(actor, target, dt);
        updateAppearance(actor, target, dt);
    }
}

function hitSegment(from, to, damage, radius = 0.12) {
    if (!app || app.gamePaused || player?.getHealth?.() <= 0) return null;
    const hit = nearestEnemyHit(from, to, enemies.map((actor) => ({
        id: actor.id, type: actor.type, health: actor.health, config: actor.config,
        position: bodyPosition(actor), actor,
    })), radius);
    if (!hit) return null;
    const actor = hit.enemy.actor;
    const amount = Math.max(0, Number(damage) || 0);
    if (amount <= 0) return null;
    actor.health = Math.max(0, actor.health - amount);
    actor.flash = 0.13;
    actor.stagger = actor.type === 'warden' ? 0.055 : 0.18;
    actor.awareness = 9;
    actor.lastSeen = targetPosition();
    actor.repath = 0;
    const position = pointOnSegment(from, to, hit.t);
    burst(position, bloodMaterial, actor.health <= 0 ? 12 : 5, actor.health <= 0 ? 1.2 : 0.8);
    if (actor.type !== 'warden' || actor.health <= 0) {
        actor.windup = 0;
        actor.charge.enabled = false;
        actor.cooldown = Math.max(actor.cooldown, 0.45);
    }
    const killed = actor.health <= 0;
    const result = { id: actor.id, type: actor.type, position, t: hit.t, health: actor.health, maxHealth: actor.config.health, killed };
    emit('hit', result);
    if (killed) {
        killCount++;
        setState(actor, 'DEAD');
        emit('kill', { ...result, position: bodyPosition(actor) });
    } else setState(actor, actor.windup > 0 ? 'ATTACK' : 'CHASE');
    return result;
}

function getEnemies() {
    return enemies.filter((actor) => actor.health > 0).map((actor) => ({
        id: actor.id, type: actor.type, name: actor.config.name, position: bodyPosition(actor),
        health: actor.health, maxHealth: actor.config.health, state: actor.state,
        radius: actor.config.radius, height: actor.config.height,
    }));
}

function getStats() {
    return { total: enemies.length, killed: killCount, remaining: enemies.length - killCount };
}

function getState() {
    return enemies[0]?.state ?? 'IDLE';
}

function cleanup() {
    app?.off('update', updateEnemies);
    enemies.forEach((actor) => actor.entity.destroy());
    enemies.length = 0;
    projectiles.forEach((projectile) => projectile.entity.destroy());
    projectiles.clear();
    particles.forEach((particle) => particle.entity.destroy());
    particles.clear();
    speciesAssets.forEach((assets) => {
        [...assets.normal, ...assets.hit, ...assets.attack].forEach((material) => material.destroy());
        assets.textures.forEach((texture) => texture.destroy());
    });
    speciesAssets.clear();
    sharedMaterials.forEach((material) => material.destroy());
    sharedMaterials.length = 0;
    projectileMaterial = projectileHaloMaterial = bloodMaterial = shadowMaterial = null;
    app = player = dungeon = pathfinder = null;
    ramps = [];
    elapsed = killCount = nextPathSearch = 0;
}

onBeforeUnmount(cleanup);
defineExpose({ setupEnemies, setupEnemy, hitSegment, getEnemies, getStats, getState, cleanup });
</script>

<template>
    <div hidden />
</template>
