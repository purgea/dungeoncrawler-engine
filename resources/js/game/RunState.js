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
function defaultSettings() {
    return { muted: false, sensitivity: 0.12, graphics: { quality: 'balanced', antialias: false } };
}

function normalizeGraphics(graphics = {}) {
    const defaults = defaultSettings().graphics;
    const quality = GRAPHICS_QUALITY_OPTIONS.some(option => option.id === graphics?.quality) ? graphics.quality : defaults.quality;
    return { quality, antialias: graphics?.antialias === true };
}

export function graphicsResolutionScale(quality) {
    return GRAPHICS_QUALITY_OPTIONS.find(option => option.id === quality)?.scale || 1;
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
    if (type === 'spikes') {
        const idleDuration = 0.5;
        const warningDuration = 0.25;
        const riseDuration = 0.2;
        const raisedDuration = 1;
        const lowerDuration = 0.2;
        const period = idleDuration + warningDuration + riseDuration + raisedDuration + lowerDuration;
        const phase = ((time % period) + period) % period;
        const warningEnd = idleDuration + warningDuration;
        const riseEnd = warningEnd + riseDuration;
        const raisedEnd = riseEnd + raisedDuration;
        const lowerEnd = raisedEnd + lowerDuration;

        if (phase < idleDuration) return { warning: false, active: false, extension: 0 };
        if (phase < warningEnd) return { warning: true, active: false, extension: 0 };
        if (phase < riseEnd) return { warning: false, active: true, extension: (phase - warningEnd) / riseDuration };
        if (phase < raisedEnd) return { warning: false, active: true, extension: 1 };
        if (phase < lowerEnd) return { warning: false, active: false, extension: 1 - (phase - raisedEnd) / lowerDuration };
        return { warning: false, active: false, extension: 0 };
    }

    const period = Number(configuredPeriod) > 0 ? Number(configuredPeriod) : 4;
    const phase = ((time + offset) % period + period) % period;
    const activeStart = period - 1.1;
    const warning = phase >= period - 2 && phase < activeStart;
    const active = phase >= activeStart;
    const extensionDuration = 0.12;
    return { warning, active, extension: active ? Math.min(1, (phase - activeStart) / extensionDuration) : 0 };
}
