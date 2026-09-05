import test, { beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { campaignLevelUrl, readCheckpoint, saveCheckpoint, nextChapterCheckpoint, clearCheckpoint, readSettings, saveSettings, formatTime, trapPhase } from '../resources/js/game/RunState.js';

const CHECKPOINT_KEY = 'ashen-realms.run.v1';
const SETTINGS_KEY = 'ashen-realms.settings.v1';
const originalStorage = globalThis.localStorage;
beforeEach(() => {
    const values = new Map();
    globalThis.localStorage = {
        getItem: key => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: key => values.delete(key),
    };
});
afterEach(() => {
    if (originalStorage === undefined) delete globalThis.localStorage;
    else globalThis.localStorage = originalStorage;
});

test('campaign URLs preserve browser-safe run codes and reject invalid destinations', () => {
    assert.equal(campaignLevelUrl({ levelSlug: '1-1', seed: 123 }), '/game/1-1?seed=123');
    assert.equal(campaignLevelUrl({ levelSlug: '1-4', seed: 2147483647 }), '/game/1-4?seed=2147483647');
    for (const seed of [0, -1, 1.5, NaN, Infinity, 2147483648, '123']) assert.equal(campaignLevelUrl({ levelSlug: '1-1', seed }), null);
    for (const levelSlug of ['../', '//outside.test', '1-1?new=1', '', undefined]) assert.equal(campaignLevelUrl({ levelSlug, seed: 123 }), null);
});

test('chapter entrance snapshots survive reload and stay independent of live player changes', () => {
    const player = { health: 85, armor: 20, weapon: { id: 'crossbow', mana: 45, unlocked: ['wand', 'crossbow'] } };
    const saved = saveCheckpoint('/game/1-2?seed=123', player, { kills: 12, elapsed: 89.5 });
    player.health = 3;
    player.weapon.unlocked.push('emberstaff');
    assert.deepEqual(readCheckpoint(), saved);
    assert.equal(saved.player.health, 85);
    assert.deepEqual(saved.player.weapon.unlocked, ['wand', 'crossbow']);
    assert.deepEqual(saved.totals, { kills: 12, elapsed: 89.5 });
    clearCheckpoint();
    assert.equal(readCheckpoint(), null);
});

test('crossing a gate immediately saves the next chapter with one bounded recovery bonus', () => {
    const result = { health: 80, armor: 40, weapon: { id: 'emberstaff', mana: 90, unlocked: ['wand', 'crossbow', 'emberstaff'] }, kills: 8, elapsed: 31.25 };
    const checkpoint = nextChapterCheckpoint('/game/1-3?seed=321', result, { kills: 20, elapsed: 100 });
    assert.equal(readCheckpoint().url, '/game/1-3?seed=321');
    assert.equal(checkpoint.player.health, 100);
    assert.equal(checkpoint.player.armor, 40);
    assert.equal(checkpoint.player.weapon.mana, 100);
    assert.equal(checkpoint.player.weapon.id, 'emberstaff');
    assert.deepEqual(checkpoint.totals, { kills: 28, elapsed: 131.25 });
    const entered = saveCheckpoint(checkpoint.url, checkpoint.player, checkpoint.totals);
    assert.deepEqual(entered.player, checkpoint.player, 'Entering/retrying does not apply the recovery bonus again.');
    assert.equal(result.health, 80);
    assert.equal(result.weapon.mana, 90);
});

test('bad checkpoint JSON and URLs cannot redirect a continue action', () => {
    for (const value of ['{', 'null', '{}', JSON.stringify({ version: 2, url: '/game/1-1?seed=1' })]) {
        localStorage.setItem(CHECKPOINT_KEY, value);
        assert.equal(readCheckpoint(), null);
    }
    for (const url of ['https://outside.test', '//outside.test', '/game/1-1?new=1', '/game/1-1?seed=0', '/game/1-1?seed=2147483648', '/game/1-1?seed=001', '/game/1-1?seed=1#fragment']) {
        localStorage.setItem(CHECKPOINT_KEY, JSON.stringify({ version: 1, url }));
        assert.equal(readCheckpoint(), null);
    }
    const valid = saveCheckpoint('/game/1-1?seed=1', {});
    assert.equal(saveCheckpoint('/game/1-1?new=1', {}), null);
    assert.deepEqual(readCheckpoint(), valid);
});

test('malformed persisted player and settings data recover to usable values', () => {
    localStorage.setItem(CHECKPOINT_KEY, JSON.stringify({ version: 1, url: '/game/1-2?seed=1', player: { health: 'bad', armor: 999, weapon: { unlocked: 14, id: 'missing', mana: -99 } }, totals: { kills: 'bad', elapsed: -12 }, savedAt: 'bad' }));
    const checkpoint = readCheckpoint();
    assert.equal(checkpoint.player.health, 100);
    assert.equal(checkpoint.player.armor, 100);
    assert.equal(checkpoint.player.weapon.id, 'wand');
    assert.equal(checkpoint.player.weapon.mana, 0);
    assert.deepEqual(checkpoint.totals, { kills: 0, elapsed: 0 });
    assert.ok(Number.isFinite(checkpoint.savedAt));
    localStorage.setItem(SETTINGS_KEY, JSON.stringify({ muted: 'false', sensitivity: 'bad' }));
    assert.deepEqual(readSettings(), { muted: false, sensitivity: 0.12 });
    saveSettings({ muted: true, sensitivity: 100 });
    assert.deepEqual(readSettings(), { muted: true, sensitivity: 0.28 });
});

test('storage failure leaves play and in-memory checkpoints available', () => {
    globalThis.localStorage = { getItem() { throw Error('Unavailable'); }, setItem() { throw Error('Unavailable'); }, removeItem() { throw Error('Unavailable'); } };
    assert.equal(readCheckpoint(), null);
    assert.equal(saveCheckpoint('/game/1-1?seed=1', {}).player.health, 100);
    assert.deepEqual(readSettings(), { muted: false, sensitivity: 0.12 });
    assert.doesNotThrow(() => { clearCheckpoint(); saveSettings({ muted: true }); });
});

test('trap warning windows precede damage and time display rejects nonfinite values', () => {
    for (const [type, period] of [['spikes', 4], ['fire', 4.8]]) {
        assert.deepEqual(trapPhase(0, 0, type), { warning: false, active: false, extension: 0 });
        assert.equal(trapPhase(period - 1.5, 0, type).warning, true);
        assert.equal(trapPhase(period - 1.5, 0, type).active, false);
        assert.equal(trapPhase(period - 0.5, 0, type).active, true);
        assert.equal(trapPhase(period, 0, type).active, false);
    }
    assert.equal(formatTime(125.9), '02:05');
    for (const value of [-1, Infinity, NaN, 'bad']) assert.equal(formatTime(value), '00:00');
});
