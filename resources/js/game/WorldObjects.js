import * as pc from 'playcanvas';
import { segmentSphereIntersection, traceDungeonSegment } from './Combat.js';
import { trapPhase } from './RunState.js';

/** Rendered, interactive level objects. The engine owns the simulation clock. */
export class WorldObjects {
    constructor(app, layout) {
        this.app = app;
        this.layout = layout;
        this.root = new pc.Entity('level-objects');
        app.root.addChild(this.root);
        this.materials = [];
        this.pickups = [];
        this.traps = [];
        this.projectiles = new Set();
        this.time = 0;
        this.portalOpen = false;
        this.stone = this.material([0.16, 0.19, 0.2]);
        this.brass = this.material([0.4, 0.29, 0.13]);
        this.fireMaterial = this.material([1, 0.14, 0.015], 4);
        this.fireHaloMaterial = this.material([1, 0.48, 0.04], 2, 0.25);
        for (const item of layout.pickups || []) this.addPickup(item);
        for (const trap of layout.traps || []) this.addTrap(trap);
        if (layout.exit) this.addPortal(layout.exit);
    }
    definition(kind, id) {
        return (this.layout.definitions?.[kind] || []).find((entry) => entry.id === id) || {};
    }
    position(item) {
        return new pc.Vec3((item.x - this.layout.width / 2) * this.layout.tileSize, item.floor, (item.y - this.layout.height / 2) * this.layout.tileSize);
    }
    material(color, glow = 0) {
        const material = new pc.StandardMaterial();
        material.diffuse.set(...color);
        material.emissive.set(...color);
        material.emissiveIntensity = glow;
        material.metalness = glow ? 0.2 : 0.4;
        material.shininess = 70;
        material.update();
        this.materials.push(material);
        return material;
    }
    mesh(parent, name, type, material, position, scale) {
        const entity = new pc.Entity(name);
        entity.addComponent('render', { type, material });
        entity.setLocalPosition(...position);
        entity.setLocalScale(...scale);
        parent.addChild(entity);
        return entity;
    }
    addPickup(data) {
        const root = new pc.Entity(`pickup-${data.id}`);
        const position = this.position(data);
        root.setPosition(position);
        this.root.addChild(root);
        const definition = data.type === 'weapon'
            ? { ...this.definition('pickup', data.type), ...this.definition('weapon', data.weapon) }
            : this.definition('pickup', data.type);
        const item = { ...definition, ...data };
        const color = item.color || [0.8, 0.8, 0.8];
        const material = this.material(color, 1.8);
        const animated = new pc.Entity('relic');
        animated.setLocalPosition(0, 0.75, 0);
        root.addChild(animated);
        this.mesh(root, 'relic-plinth', 'cylinder', this.brass, [0, 0.06, 0], [0.64, 0.12, 0.64]);
        if (data.type === 'health') {
            this.mesh(animated, 'vial', 'sphere', material, [0, 0, 0], [0.3, 0.42, 0.3]);
            this.mesh(animated, 'vial-stopper', 'cylinder', this.brass, [0, 0.26, 0], [0.15, 0.14, 0.15]);
            this.mesh(animated, 'vial-cross', 'box', this.brass, [0, 0, 0.15], [0.2, 0.06, 0.02]);
        } else if (data.type === 'armor') {
            this.mesh(animated, 'shield', 'sphere', material, [0, 0, 0], [0.52, 0.65, 0.15]);
            this.mesh(animated, 'shield-boss', 'sphere', this.brass, [0, 0, 0.09], [0.17, 0.17, 0.09]);
        } else if (data.type === 'weapon') {
            this.mesh(animated, 'relic-shaft', 'cylinder', this.brass, [0, 0, 0], [0.08, 0.8, 0.08]);
            if (data.weapon === 'crossbow') {
                this.mesh(animated, 'bow', 'box', material, [0, 0.15, 0], [0.75, 0.08, 0.08]);
            } else {
                this.mesh(animated, 'staff-crystal', 'cone', material, [0, 0.42, 0], [0.27, 0.35, 0.27]);
            }
        } else {
            const size = data.type === 'sigil' ? 0.55 : 0.28;
            this.mesh(animated, 'crystal-upper', 'cone', material, [0, size * 0.3, 0], [size, size * 0.8, size]);
            const lower = this.mesh(animated, 'crystal-lower', 'cone', material, [0, -size * 0.3, 0], [size, size * 0.5, size]);
            lower.setLocalEulerAngles(180, 0, 0);
            if (data.type === 'sigil') {
                for (let i = 0; i < 4; i++) {
                    const rune = this.mesh(animated, 'sigil-shard', 'box', material, [Math.cos(i * Math.PI / 2) * 0.55, 0, Math.sin(i * Math.PI / 2) * 0.55], [0.08, 0.28, 0.08]);
                    rune.setLocalEulerAngles(0, 0, 45);
                }
            }
        }
        this.pickups.push({ ...item, root, animated, position, collected: false });
    }
    addTrap(data) {
        const root = new pc.Entity(`trap-${data.id}`);
        const position = this.position(data);
        root.setPosition(position);
        this.root.addChild(root);
        const width = this.layout.tileSize * 0.72;
        const definition = this.definition('trap', data.type);
        const trapData = { ...definition, ...data };
        if (trapData.mount === 'wall') {
            this.addWallHead(root, trapData);
            this.traps.push({ ...trapData, root, spikes: null, warning: trapData.warningMaterial, position, width: this.layout.tileSize * 0.5, lastDamage: -Infinity, cooldown: 0, visibilityTimer: 0, visible: false, wasWarning: false });
            return;
        }
        this.mesh(root, 'pressure-plate', 'box', this.stone, [0, 0.025, 0], [width, 0.05, width]);
        const warning = this.material(trapData.warning_color || [0.44, 0.12, 0.03], 0.4);
        for (const x of [-1, 1]) this.mesh(root, 'trap-rune', 'box', warning, [x * width / 2, 0.07, 0], [0.06, 0.03, width]);
        for (const z of [-1, 1]) this.mesh(root, 'trap-rune', 'box', warning, [0, 0.07, z * width / 2], [width, 0.03, 0.06]);
        const spikes = new pc.Entity('trap-hazard');
        root.addChild(spikes);
        const hazardMaterial = this.material(trapData.color || [0.7, 0.7, 0.7], trapData.emissive ? 3 : 0);
        for (let x = -1; x <= 1; x++) for (let z = -1; z <= 1; z++) {
            this.mesh(root, 'vent', 'cylinder', this.brass, [x * width / 3.5, 0.06, z * width / 3.5], [0.24, 0.05, 0.24]);
            const spikeSize = trapData.id === 'fire' ? 0.4 : 0.15;
            this.mesh(spikes, 'spike', 'cone', hazardMaterial, [x * width / 3.5, 0.7, z * width / 3.5], [spikeSize, 1.4, spikeSize]);
        }
        spikes.setLocalScale(1, 0.01, 1);
        this.traps.push({ ...trapData, root, spikes, warning, position, width, lastDamage: -Infinity, wasWarning: false });
    }
    sideVector(side) {
        return ({ north: { x: 0, z: -1 }, east: { x: 1, z: 0 }, south: { x: 0, z: 1 }, west: { x: -1, z: 0 } })[side] || { x: 0, z: -1 };
    }
    forwardVector(side) {
        const wall = this.sideVector(side);
        return { x: -wall.x, z: -wall.z };
    }
    wallOrigin(trap) {
        const side = this.sideVector(trap.wall_side);
        const forward = this.forwardVector(trap.wall_side);
        const offset = this.layout.tileSize / 2 - 0.42;
        return { x: trap.position.x + side.x * offset + forward.x * 0.3, y: trap.position.y + 1.7, z: trap.position.z + side.z * offset + forward.z * 0.3 };
    }
    addWallHead(root, trap) {
        const side = this.sideVector(trap.wall_side);
        const offset = this.layout.tileSize / 2 - 0.42;
        const warning = this.material(trap.warning_color || [1, 0.38, 0.04], 1.2);
        const head = new pc.Entity('wall-fire-head');
        head.setLocalPosition(side.x * offset, 1.7, side.z * offset);
        head.setLocalEulerAngles(0, Math.atan2(side.x, side.z) * 180 / Math.PI, 0);
        root.addChild(head);
        this.mesh(head, 'head-mount', 'cylinder', this.brass, [0, -0.55, 0], [0.42, 0.18, 0.42]);
        this.mesh(head, 'head-skull', 'sphere', this.stone, [0, 0, 0], [0.48, 0.52, 0.4]);
        this.mesh(head, 'head-jaw', 'box', this.brass, [0, -0.25, -0.05], [0.34, 0.16, 0.3]);
        this.mesh(head, 'head-flame-eye', 'sphere', warning, [0, 0.03, -0.32], [0.14, 0.1, 0.06]);
        trap.warningMaterial = warning;
    }
    spawnFireball(trap) {
        const origin = this.wallOrigin(trap);
        const forward = this.forwardVector(trap.wall_side);
        const direction = new pc.Vec3(forward.x, 0, forward.z).normalize();
        const entity = new pc.Entity('wall-fireball');
        entity.addComponent('render', { type: 'sphere', material: this.fireMaterial, castShadows: false });
        entity.setPosition(origin.x, origin.y, origin.z);
        entity.setLocalScale(0.22, 0.22, 0.22);
        const halo = new pc.Entity('wall-fireball-halo');
        halo.addComponent('render', { type: 'sphere', material: this.fireHaloMaterial, castShadows: false });
        halo.setLocalScale(2.4, 2.4, 2.4);
        entity.addChild(halo);
        this.root.addChild(entity);
        this.projectiles.add({ entity, direction, age: 0, speed: Number(trap.projectile_speed) || 18, damage: Number(trap.damage) || 18, trap });
    }
    updateProjectiles(dt, target, onTrap) {
        const playerBody = { x: target.x, y: target.y - 0.7, z: target.z };
        for (const projectile of this.projectiles) {
            const old = projectile.entity.getPosition();
            const from = { x: old.x, y: old.y, z: old.z };
            const to = { x: from.x + projectile.direction.x * projectile.speed * dt, y: from.y + projectile.direction.y * projectile.speed * dt, z: from.z + projectile.direction.z * projectile.speed * dt };
            projectile.age += dt;
            const wall = traceDungeonSegment(from, to, this.layout, 0.12);
            const playerHit = segmentSphereIntersection(from, to, playerBody, 0.9);
            const hitPlayer = playerHit !== null && (!wall || playerHit < wall.t);
            if (hitPlayer || wall || projectile.age > 5) {
                if (hitPlayer) onTrap({ ...projectile.trap, damage: projectile.damage });
                projectile.entity.destroy();
                this.projectiles.delete(projectile);
                continue;
            }
            projectile.entity.setPosition(to.x, to.y, to.z);
            const pulse = 0.2 + Math.sin(this.time * 24) * 0.03;
            projectile.entity.setLocalScale(pulse, pulse, pulse);
        }
    }
    addPortal(data) {
        const root = new pc.Entity('exit-portal');
        const position = this.position(data);
        root.setPosition(position);
        this.root.addChild(root);
        this.portalMaterial = this.material([0.23, 0.14, 0.38], 0.65);
        this.mesh(root, 'portal-step', 'cylinder', this.stone, [0, 0.09, 0], [2.7, 0.18, 2.7]);
        for (const x of [-1.1, 1.1]) this.mesh(root, 'portal-pillar', 'box', this.brass, [x, 1.2, 0], [0.3, 2.4, 0.4]);
        this.mesh(root, 'portal-lintel', 'box', this.brass, [0, 2.45, 0], [2.5, 0.3, 0.4]);
        this.portalVeil = this.mesh(root, 'portal-veil', 'sphere', this.portalMaterial, [0, 1.2, 0], [1.85, 2.25, 0.14]);
        this.runes = [];
        for (let i = 0; i < 3; i++) {
            const rune = this.mesh(root, 'portal-sigil', 'box', this.portalMaterial, [(i - 1) * 0.55, 2.46, 0.23], [0.18, 0.18, 0.07]);
            rune.setLocalEulerAngles(0, 0, 45);
            this.runes.push(rune);
        }
        this.portal = { ...data, root, position };
    }
    openPortal() {
        if (this.portalOpen) return;
        this.portalOpen = true;
        this.portalMaterial.diffuse.set(0.12, 0.7, 0.52);
        this.portalMaterial.emissive.set(0.12, 0.85, 0.59);
        this.portalMaterial.emissiveIntensity = 2.2;
        this.portalMaterial.update();
    }
    update(dt, camera, { onPickup, onTrap, onWarning }) {
        this.time += dt;
        const player = camera.getPosition();
        for (const item of this.pickups) {
            if (item.collected) continue;
            item.animated.setLocalPosition(0, 0.75 + Math.sin(this.time * 2.6 + item.x) * 0.13, 0);
            item.animated.rotateLocal(0, dt * 65, 0);
            if (Math.hypot(player.x - item.position.x, player.z - item.position.z) < 1.15 && Math.abs(player.y - 1.55 - item.floor) < 1 && onPickup(item)) {
                item.collected = true;
                item.root.enabled = false;
            }
        }
        for (const trap of this.traps) {
            if (trap.mount === 'wall') {
                const origin = this.wallOrigin(trap);
                const target = { x: player.x, y: player.y - 0.45, z: player.z };
                trap.visibilityTimer -= dt;
                if (trap.visibilityTimer <= 0) {
                    trap.visible = traceDungeonSegment(origin, target, this.layout, 0.08) === null;
                    trap.visibilityTimer = 0.12;
                }
                const visible = trap.visible;
                trap.cooldown -= dt;
                trap.warningMaterial.emissiveIntensity = visible ? 1.3 + Math.sin(this.time * 9) * 0.35 : 0.35;
                trap.warningMaterial.update();
                if (visible && trap.cooldown <= 0) {
                    this.spawnFireball(trap);
                    trap.cooldown = Number(trap.period) > 0 ? Number(trap.period) : 1;
                }
                continue;
            }
            const phase = trapPhase(this.time, trap.phase, trap.type, trap.period);
            trap.spikes.setLocalScale(1, Math.max(0.01, phase.extension), 1);
            trap.warning.emissiveIntensity = phase.active ? 4 : phase.warning ? 1.5 + Math.sin(this.time * 30) : 0.25;
            trap.warning.update();
            const near = Math.hypot(player.x - trap.position.x, player.z - trap.position.z) < 12 && Math.abs(player.y - 1.55 - trap.floor) < 1;
            if (phase.warning && !trap.wasWarning && near) onWarning(trap);
            trap.wasWarning = phase.warning;
            if (phase.active && Math.abs(player.x - trap.position.x) < trap.width / 2 + 0.2 && Math.abs(player.z - trap.position.z) < trap.width / 2 + 0.2 && Math.abs(player.y - 1.55 - trap.floor) < 1 && this.time - trap.lastDamage > 0.8) {
                trap.lastDamage = this.time;
                onTrap(trap);
            }
        }
        this.updateProjectiles(dt, player, onTrap);
        if (this.portalVeil) this.portalVeil.setLocalScale(1.85 + Math.sin(this.time * 2) * 0.05, 2.25, 0.14 + Math.sin(this.time * 3) * 0.05);
    }
    nearPortal(camera) {
        if (!this.portal) return false;
        const p = camera.getPosition();
        return Math.hypot(p.x - this.portal.position.x, p.z - this.portal.position.z) < 2.8 && Math.abs(p.y - 1.55 - this.portal.floor) < 1;
    }
    markers() { return this.pickups.filter(p => !p.collected).map(({ id, x, y, floor, type, color }) => ({ id, x, y, floor, type, color })); }
    dispose() { this.root.destroy(); this.projectiles.forEach((projectile) => projectile.entity.destroy()); this.projectiles.clear(); this.materials.forEach(m => m.destroy()); this.pickups = []; this.traps = []; }
}
