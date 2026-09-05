const DEFAULT_MAX_MANA = 100;

function normalizeDefinition(definition, index) {
    return {
        ...definition,
        id: definition.id,
        slot: Number(definition.slot ?? index + 1),
        cost: Number(definition.cost ?? 0),
        damage: Number(definition.damage ?? 1),
        cooldown: Number(definition.cooldown ?? 0.5),
        speed: Number(definition.speed ?? 30),
        radius: Number(definition.radius ?? 0.1),
        range: Number(definition.range ?? 60),
        color: definition.color || [0.5, 0.8, 1],
    };
}

const definitionFor = (definitions, idOrSlot) => definitions.find((weapon) => (
    weapon.id === idOrSlot || weapon.slot === Number(idOrSlot)
));

/** Runtime weapon inventory. Definitions come from the active world stage. */
export class Arsenal {
    constructor(definitions = [], state = {}, maxMana = null) {
        this.definitions = definitions.map(normalizeDefinition).sort((a, b) => a.slot - b.slot);
        this.maxMana = Number(state.maxMana ?? maxMana ?? DEFAULT_MAX_MANA);
        this.cooldown = 0;
        this.restore(state);
    }

    restore(state = {}) {
        this.unlocked = new Set([
            ...this.definitions.filter((weapon) => weapon.starting).map((weapon) => weapon.id),
            ...(Array.isArray(state.unlocked) ? state.unlocked.filter((id) => definitionFor(this.definitions, id)?.id === id) : []),
        ]);
        if (!this.unlocked.size && this.definitions[0]) this.unlocked.add(this.definitions[0].id);
        this.id = this.unlocked.has(state.id) ? state.id : this.definitions.find((weapon) => this.unlocked.has(weapon.id))?.id;
        this.maxMana = Math.max(1, Number(state.maxMana ?? this.maxMana) || DEFAULT_MAX_MANA);
        this.mana = Number.isFinite(state.mana) ? Math.max(0, Math.min(this.maxMana, Math.floor(state.mana))) : this.maxMana * 0.6;
        this.cooldown = 0;
    }

    get weapon() { return definitionFor(this.definitions, this.id) || this.definitions[0]; }
    tick(dt) { this.cooldown = Math.max(0, this.cooldown - Math.max(0, dt)); }

    fire() {
        const weapon = this.weapon;
        if (!weapon) return { fired: false, reason: 'missing', weapon: null };
        if (this.cooldown > 1e-6) return { fired: false, reason: 'cooldown', weapon };
        if (this.mana < weapon.cost) {
            this.id = this.definitions.find((entry) => this.unlocked.has(entry.id) && entry.cost === 0)?.id || this.id;
            return { fired: false, reason: 'mana', weapon };
        }
        this.mana -= weapon.cost;
        this.cooldown = weapon.cooldown;
        return { fired: true, weapon };
    }

    select(idOrSlot) {
        const weapon = definitionFor(this.definitions, idOrSlot);
        if (!weapon || !this.unlocked.has(weapon.id) || this.id === weapon.id) return false;
        this.id = weapon.id;
        return true;
    }

    cycle(direction = 1) {
        const choices = this.definitions.filter((weapon) => this.unlocked.has(weapon.id));
        if (!choices.length) return false;
        const index = choices.findIndex((weapon) => weapon.id === this.id);
        return this.select(choices[(index + (direction < 0 ? -1 : 1) + choices.length) % choices.length].id);
    }

    addMana(amount) {
        if (!Number.isFinite(amount) || amount <= 0) return 0;
        const previous = this.mana;
        this.mana = Math.min(this.maxMana, this.mana + Math.floor(amount));
        return this.mana - previous;
    }

    unlock(id) {
        const weapon = definitionFor(this.definitions, id);
        if (!weapon || this.unlocked.has(weapon.id)) return false;
        this.unlocked.add(weapon.id);
        this.id = weapon.id;
        return true;
    }

    getState() {
        const weapon = this.weapon || {};
        return {
            id: weapon.id,
            name: weapon.name,
            slot: weapon.slot,
            mana: this.mana,
            maxMana: this.maxMana,
            unlocked: this.definitions.filter((entry) => this.unlocked.has(entry.id)).map((entry) => entry.id),
            weapons: this.definitions.map((entry) => ({ ...entry, unlocked: this.unlocked.has(entry.id) })),
        };
    }
}
