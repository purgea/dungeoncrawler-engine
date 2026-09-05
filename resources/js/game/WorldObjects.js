import * as pc from 'playcanvas';
import { trapPhase } from './RunState.js';

const COLORS = { health: [0.8, 0.12, 0.16], armor: [0.34, 0.66, 0.76], mana: [0.25, 0.48, 1], weapon: [1, 0.64, 0.2], sigil: [0.55, 0.9, 0.72] };

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
        this.time = 0;
        this.portalOpen = false;
        this.stone = this.material([0.16, 0.19, 0.2]);
        this.brass = this.material([0.4, 0.29, 0.13]);
        for (const item of layout.pickups || []) this.addPickup(item);
        for (const trap of layout.traps || []) this.addTrap(trap);
        if (layout.exit) this.addPortal(layout.exit);
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
        const color = COLORS[data.type] || COLORS.mana;
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
        this.pickups.push({ ...data, root, animated, position, collected: false });
    }
    addTrap(data) {
        const root = new pc.Entity(`trap-${data.id}`);
        const position = this.position(data);
        root.setPosition(position);
        this.root.addChild(root);
        const width = this.layout.tileSize * 0.72;
        this.mesh(root, 'pressure-plate', 'box', this.stone, [0, 0.025, 0], [width, 0.05, width]);
        const warning = this.material([0.44, 0.12, 0.03], 0.4);
        for (const x of [-1, 1]) this.mesh(root, 'trap-rune', 'box', warning, [x * width / 2, 0.07, 0], [0.06, 0.03, width]);
        for (const z of [-1, 1]) this.mesh(root, 'trap-rune', 'box', warning, [0, 0.07, z * width / 2], [width, 0.03, 0.06]);
        const spikes = new pc.Entity('trap-hazard');
        root.addChild(spikes);
        const hazardMaterial = data.type === 'fire' ? this.material([1, 0.21, 0.015], 3) : this.material([0.48, 0.49, 0.46]);
        for (let x = -1; x <= 1; x++) for (let z = -1; z <= 1; z++) {
            this.mesh(root, 'vent', 'cylinder', this.brass, [x * width / 3.5, 0.06, z * width / 3.5], [0.24, 0.05, 0.24]);
            this.mesh(spikes, 'spike', 'cone', hazardMaterial, [x * width / 3.5, 0.7, z * width / 3.5], [data.type === 'fire' ? 0.4 : 0.15, 1.4, data.type === 'fire' ? 0.4 : 0.15]);
        }
        spikes.setLocalScale(1, 0.01, 1);
        this.traps.push({ ...data, root, spikes, warning, position, width, lastDamage: -Infinity, wasWarning: false });
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
            const phase = trapPhase(this.time, trap.phase, trap.type);
            trap.spikes.setLocalScale(1, phase.active ? phase.extension : 0.01, 1);
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
        if (this.portalVeil) this.portalVeil.setLocalScale(1.85 + Math.sin(this.time * 2) * 0.05, 2.25, 0.14 + Math.sin(this.time * 3) * 0.05);
    }
    nearPortal(camera) {
        if (!this.portal) return false;
        const p = camera.getPosition();
        return Math.hypot(p.x - this.portal.position.x, p.z - this.portal.position.z) < 2.8 && Math.abs(p.y - 1.55 - this.portal.floor) < 1;
    }
    markers() { return this.pickups.filter(p => !p.collected).map(({ id, x, y, floor, type }) => ({ id, x, y, floor, type })); }
    dispose() { this.root.destroy(); this.materials.forEach(m => m.destroy()); this.pickups = []; this.traps = []; }
}
