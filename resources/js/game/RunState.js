import { Arsenal } from './Weapons.js';

const KEY = 'ashen-realms.run.v1';
const SETTINGS_KEY = 'ashen-realms.settings.v1';
const MAX_SEED = 2147483647;
const finite = (value, fallback) => Number.isFinite(value) ? value : fallback;
const bounded = (value, fallback, min, max) => Math.max(min, Math.min(max, finite(value, fallback)));

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
        weapon: new Arsenal(player?.weapon && typeof player.weapon === 'object' ? player.weapon : {}).getState(),
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
    player.weapon.mana = Math.min(100, player.weapon.mana + 20);
    return saveCheckpoint(url, player, { kills: previous.kills + chapter.kills, elapsed: previous.elapsed + chapter.elapsed });
}

export function clearCheckpoint() {
    try { localStorage.removeItem(KEY); } catch { /* Private storage may be unavailable. */ }
}

export function readSettings() {
    const defaults = { muted: false, sensitivity: 0.12 };
    try {
        const stored = JSON.parse(localStorage.getItem(SETTINGS_KEY));
        return { muted: stored?.muted === true, sensitivity: bounded(stored?.sensitivity, defaults.sensitivity, 0.04, 0.28) };
    } catch { return defaults; }
}

export function saveSettings(settings) {
    try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings)); } catch { /* Optional preference. */ }
}

export function formatTime(seconds) {
    const safe = Math.max(0, Math.floor(finite(Number(seconds), 0)));
    return `${Math.floor(safe / 60).toString().padStart(2, '0')}:${(safe % 60).toString().padStart(2, '0')}`;
}

export function trapPhase(time, offset = 0, type = 'spikes') {
    const period = type === 'fire' ? 4.8 : 4;
    const phase = ((time + offset) % period + period) % period;
    const warning = phase >= period - 2 && phase < period - 1.1;
    const active = phase >= period - 1.1;
    return { warning, active, extension: active ? Math.min(1, (phase - (period - 1.1)) / 0.12) : 0 };
}
