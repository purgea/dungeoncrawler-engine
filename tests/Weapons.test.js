import test from 'node:test';
import assert from 'node:assert/strict';
import { Arsenal, MAX_MANA, WEAPONS } from '../resources/js/game/Weapons.js';

test('the starting wand always works without ammunition and locked slots cannot be selected', () => {
    const arsenal = new Arsenal({ mana: 0 });
    assert.deepEqual(arsenal.getState().unlocked, ['wand']);
    assert.equal(arsenal.select(2), false);
    assert.equal(arsenal.fire().fired, true);
    assert.equal(arsenal.mana, 0);
    assert.equal(arsenal.fire().reason, 'cooldown');
    arsenal.tick(WEAPONS[0].cooldown);
    assert.equal(arsenal.fire().fired, true);
});

test('weapon unlocks equip the pickup, ammo is spent once per volley, and dry fire restores the wand', () => {
    const arsenal = new Arsenal({ mana: 5 });
    assert.equal(arsenal.unlock('crossbow'), true);
    assert.equal(arsenal.id, 'crossbow');
    assert.equal(arsenal.fire().fired, true);
    assert.equal(arsenal.mana, 0);
    arsenal.tick(1);
    assert.equal(arsenal.fire().reason, 'mana');
    assert.equal(arsenal.id, 'wand');
    assert.equal(arsenal.fire().fired, true);
});

test('cycling skips locked weapons and swapping cannot bypass a shot cooldown', () => {
    const arsenal = new Arsenal();
    arsenal.unlock('emberstaff');
    assert.equal(arsenal.fire().fired, true);
    assert.equal(arsenal.cycle(1), true);
    assert.equal(arsenal.id, 'wand');
    assert.equal(arsenal.fire().reason, 'cooldown');
    arsenal.tick(0.4);
    arsenal.select(3);
    assert.equal(arsenal.fire().reason, 'cooldown');
    arsenal.tick(0.5);
    assert.equal(arsenal.fire().fired, true);
    assert.equal(arsenal.cycle(-1), true);
    assert.equal(arsenal.id, 'wand');
});

test('restoring a run preserves its valid arsenal and clamps malformed ammunition', () => {
    const arsenal = new Arsenal();
    arsenal.unlock('crossbow');
    arsenal.addMana(999);
    assert.equal(arsenal.mana, MAX_MANA);
    assert.equal(arsenal.addMana(5), 0);
    assert.equal(arsenal.addMana(-50), 0);
    assert.equal(arsenal.addMana(NaN), 0);
    const restored = new Arsenal(JSON.parse(JSON.stringify(arsenal.getState())));
    assert.deepEqual(restored.getState(), arsenal.getState());
    restored.restore({ id: 'missing', unlocked: ['missing', 'emberstaff'], mana: -100 });
    assert.equal(restored.id, 'wand');
    assert.deepEqual(restored.getState().unlocked, ['wand', 'emberstaff']);
    assert.equal(restored.mana, 0);
    assert.equal(restored.unlock('missing'), false);
    assert.equal(restored.unlock('wand'), false);
});
