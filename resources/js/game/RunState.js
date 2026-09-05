const KEY = 'ashen-realms.run.v1';
const SETTINGS_KEY = 'ashen-realms.settings.v1';
const MAX_SEED = 2147483647;
const finite = (value, fallback) => Number.isFinite(value) ? value : fallback;
const bounded = (value, fallback, min, max) => Math.max(min, Math.min(max, finite(value, fallback)));

export const GRAPHICS_QUALITY_OPTIONS = [
    { id: 'performance', label: 'Performance', scale: 0.75 },
    { id: 'balanced', label: 'Balanced', scale: 1 },
    { id: 'quality', label: 'Quality', scale: 1.25 },
];
export const GRAPHICS_LIGHTING_OPTIONS = [
    { id: 'nearby', label: 'Nearby lights', distanceTiles: 8 },
    { id: 'extended', label: 'Extended lights', distanceTiles: 12 },
];

function defaultSettings() {
    return { muted: false, sensitivity: 0.12, graphics: { quality: 'balanced', antialias: false, lighting: 'nearby' } };
}

function normalizeGraphics(graphics = {}) {
    const defaults = defaultSettings().graphics;
    const quality = GRAPHICS_QUALITY_OPTIONS.some(option => option.id === graphics?.quality) ? graphics.quality : defaults.quality;
    const lighting = GRAPHICS_LIGHTING_OPTIONS.some(option => option.id === graphics?.lighting) ? graphics.lighting : defaults.lighting;
    return { quality, antialias: graphics?.antialias === true, lighting };
}

export function graphicsResolutionScale(quality) {
    return GRAPHICS_QUALITY_OPTIONS.find(option => option.id === quality)?.scale || 1;
}

export function graphicsLightDistanceTiles(lighting) {
    return GRAPHICS_LIGHTING_OPTIONS.find(option => option.id === lighting)?.distanceTiles || 8;
}

export function campaignLevelUrl({ levelSlug, seed } = {}) {
    if (typeof levelSlug !== 'string' || !/^[a-zA-Z0-9_-]+$/.test(levelSlug) || !Number.isInteger(seed) || seed < 1 || seed > MAX_SEED) return null;
    return `/game/${levelSlug}?seed=${seed}`;
}

function validCheckpointUrl(url) {
    const match = typeof url === 'string' && /^\/game\/([a-zA-Z0-9_-]+)\?seed=(\d+)$/.exec(url);
    return Boolean(match && campaignLevelUrl({ levelSlug: match[1], seed: Number(match[2]) }) === url);
}

function normalizePlayer(player = {}) {
    return {
        health: bounded(player?.health, 100, 1, 100),
        armor: bounded(player?.armor, 0, 0, 100),
        weapon: normalizeWeapon(player?.weapon),
    };
}

function normalizeWeapon(weapon = {}) {
    const source = weapon && typeof weapon === 'object' ? weapon : {};
    const maxMana = bounded(source.maxMana, 100, 1, 1000);
    const unlocked = Array.isArray(source.unlocked)
        ? [...new Set(source.unlocked.filter((id) => typeof id === 'string' && /^[a-zA-Z0-9_-]+$/.test(id)))]
        : [];
    return {
        id: typeof source.id === 'string' ? source.id : null,
        name: typeof source.name === 'string' ? source.name : null,
        slot: Number.isInteger(source.slot) ? source.slot : null,
        mana: bounded(source.mana, maxMana * 0.6, 0, maxMana),
        maxMana,
        unlocked,
        weapons: Array.isArray(source.weapons) ? source.weapons : [],
    };
}

function normalizeTotals(totals = {}) {
    return { kills: Math.max(0, Math.floor(finite(totals?.kills, 0))), elapsed: Math.max(0, finite(totals?.elapsed, 0)) };
}

export function readCheckpoint() {
    try {
        const state = JSON.parse(localStorage.getItem(KEY));
        if (state?.version !== 1 || !validCheckpointUrl(state.url)) return null;
        return { version: 1, url: state.url, player: normalizePlayer(state.player), totals: normalizeTotals(state.totals), savedAt: bounded(state.savedAt, Date.now(), 0, 8640000000000000) };
    } catch { return null; }
}

export function saveCheckpoint(url, player, totals = {}) {
    if (!validCheckpointUrl(url)) return null;
    const state = { version: 1, url, player: normalizePlayer(player), totals: normalizeTotals(totals), savedAt: Date.now() };
    try { localStorage.setItem(KEY, JSON.stringify(state)); } catch { /* Play remains available without storage. */ }
    return state;
}

/** Persist the next chapter entrance as soon as its preceding gate is crossed. */
export function nextChapterCheckpoint(url, result, totals = {}) {
    const player = normalizePlayer(result);
    const previous = normalizeTotals(totals);
    const chapter = normalizeTotals(result);
    player.health = Math.min(100, player.health + 25);
    player.weapon.mana = Math.min(player.weapon.maxMana, player.weapon.mana + 20);
    return saveCheckpoint(url, player, { kills: previous.kills + chapter.kills, elapsed: previous.elapsed + chapter.elapsed });
}

export function clearCheckpoint() {
    try { localStorage.removeItem(KEY); } catch { /* Private storage may be unavailable. */ }
}

export function readSettings() {
    const defaults = defaultSettings();
    try {
        const stored = JSON.parse(localStorage.getItem(SETTINGS_KEY));
        return {
            muted: stored?.muted === true,
            sensitivity: bounded(stored?.sensitivity, defaults.sensitivity, 0.04, 0.28),
            graphics: normalizeGraphics(stored?.graphics),
        };
    } catch { return defaults; }
}

export function saveSettings(settings) {
    const source = settings && typeof settings === 'object' ? settings : {};
    const normalized = {
        muted: source.muted === true,
        sensitivity: bounded(source.sensitivity, 0.12, 0.04, 0.28),
        graphics: normalizeGraphics(source.graphics),
    };
    try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(normalized)); } catch { /* Optional preference. */ }
}

export function formatTime(seconds) {
    const safe = Math.max(0, Math.floor(finite(Number(seconds), 0)));
    return `${Math.floor(safe / 60).toString().padStart(2, '0')}:${(safe % 60).toString().padStart(2, '0')}`;
}

export function trapPhase(time, offset = 0, type = 'spikes', configuredPeriod = null) {
    const period = Number(configuredPeriod) > 0 ? Number(configuredPeriod) : 4;
    const phase = ((time + offset) % period + period) % period;
    const warning = phase >= period - 2 && phase < period - 1.1;
    const active = phase >= period - 1.1;
    return { warning, active, extension: active ? Math.min(1, (phase - (period - 1.1)) / 0.12) : 0 };
}
