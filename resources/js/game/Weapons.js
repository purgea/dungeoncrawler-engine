export const MAX_MANA = 100;

export const WEAPONS = Object.freeze([
    Object.freeze({ id: 'wand', name: 'Aether Wand', slot: 1, cost: 0, damage: 22, cooldown: 0.3, speed: 34, radius: 0.1, range: 60, color: [0.24, 0.7, 1], description: 'Swift arcane bolts · infinite charge' }),
    Object.freeze({ id: 'crossbow', name: 'Grave Crossbow', slot: 2, cost: 5, damage: 25, cooldown: 0.6, speed: 60, radius: 0.075, range: 80, color: [0.34, 1, 0.54], description: 'Three spectral bolts · 5 mana' }),
    Object.freeze({ id: 'emberstaff', name: 'Ember Staff', slot: 3, cost: 12, damage: 95, cooldown: 0.9, speed: 24, radius: 0.25, range: 65, color: [1, 0.3, 0.06], description: 'Heavy infernal fireball · 12 mana' }),
]);

const definition = (idOrSlot) => WEAPONS.find((weapon) => weapon.id === idOrSlot || weapon.slot === Number(idOrSlot));

/** Serializable inventory with a simulation-time fire rate (pause freezes it). */
export class Arsenal {
    constructor(state = {}) {
        this.restore(state);
    }

    restore(state = {}) {
        this.unlocked = new Set(['wand', ...(Array.isArray(state.unlocked) ? state.unlocked.filter((id) => definition(id)?.id === id) : [])]);
        this.id = this.unlocked.has(state.id) ? state.id : 'wand';
        this.mana = Number.isFinite(state.mana) ? Math.max(0, Math.min(MAX_MANA, Math.floor(state.mana))) : 60;
        this.cooldown = 0;
    }

    get weapon() { return definition(this.id); }

    tick(dt) { this.cooldown = Math.max(0, this.cooldown - Math.max(0, dt)); }

    fire() {
        const weapon = this.weapon;
        if (this.cooldown > 1e-6) return { fired: false, reason: 'cooldown', weapon };
        if (this.mana < weapon.cost) {
            this.id = 'wand';
            return { fired: false, reason: 'mana', weapon };
        }
        this.mana -= weapon.cost;
        this.cooldown = weapon.cooldown;
        return { fired: true, weapon };
    }

    select(idOrSlot) {
        const weapon = definition(idOrSlot);
        if (!weapon || !this.unlocked.has(weapon.id) || this.id === weapon.id) return false;
        this.id = weapon.id;
        return true;
    }

    cycle(direction = 1) {
        const choices = WEAPONS.filter((weapon) => this.unlocked.has(weapon.id));
        const index = choices.findIndex((weapon) => weapon.id === this.id);
        return this.select(choices[(index + (direction < 0 ? -1 : 1) + choices.length) % choices.length].id);
    }

    addMana(amount) {
        if (!Number.isFinite(amount) || amount <= 0) return 0;
        const previous = this.mana;
        this.mana = Math.min(MAX_MANA, this.mana + Math.floor(amount));
        return this.mana - previous;
    }

    unlock(id) {
        const weapon = definition(id);
        if (!weapon || this.unlocked.has(weapon.id)) return false;
        this.unlocked.add(weapon.id);
        this.id = weapon.id;
        return true;
    }

    getState() {
        return {
            id: this.id,
            name: this.weapon.name,
            slot: this.weapon.slot,
            mana: this.mana,
            maxMana: MAX_MANA,
            unlocked: WEAPONS.filter((weapon) => this.unlocked.has(weapon.id)).map((weapon) => weapon.id),
            weapons: WEAPONS.map((weapon) => ({ ...weapon, unlocked: this.unlocked.has(weapon.id) })),
        };
    }
}
