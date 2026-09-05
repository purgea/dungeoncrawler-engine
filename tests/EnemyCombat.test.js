import test from 'node:test';
import assert from 'node:assert/strict';
import { nearestEnemyHit, segmentBodyIntersection } from '../resources/js/game/EnemyCombat.js';

const feet = { x: 0, y: 0, z: 0 };
test('fast projectiles sweep a body between frames', () => {
    const t = segmentBodyIntersection({ x: -10, y: 1, z: 0 }, { x: 10, y: 1, z: 0 }, feet, 0.5, 2);
    assert.equal(t, 0.475);
});
test('shots above and below a body do not hit a different elevation', () => {
    assert.equal(segmentBodyIntersection({ x: -2, y: 3, z: 0 }, { x: 2, y: 3, z: 0 }, feet, 0.5, 2), null);
    assert.equal(segmentBodyIntersection({ x: -2, y: -1, z: 0 }, { x: 2, y: -1, z: 0 }, feet, 0.5, 2), null);
});
test('vertical fire intersects the head and radius expands the hitbox', () => {
    assert.equal(segmentBodyIntersection({ x: 0, y: 4, z: 0 }, { x: 0, y: 0, z: 0 }, feet, 0.5, 2), 0.5);
    assert.equal(segmentBodyIntersection({ x: -2, y: 1, z: 0.6 }, { x: 2, y: 1, z: 0.6 }, feet, 0.5, 2), null);
    assert.notEqual(segmentBodyIntersection({ x: -2, y: 1, z: 0.6 }, { x: 2, y: 1, z: 0.6 }, feet, 0.5, 2, 0.15), null);
});
test('a diagonal ray must overlap horizontal and vertical bounds at the same time', () => {
    assert.equal(segmentBodyIntersection({ x: -2, y: 2, z: 0 }, { x: 2, y: 10, z: 0 }, feet, 0.5, 2), null);
});
test('shots beginning inside a body hit immediately; zero-length misses stay misses', () => {
    assert.equal(segmentBodyIntersection({ x: 0, y: 1, z: 0 }, { x: 2, y: 1, z: 0 }, feet, 0.5, 2), 0);
    assert.equal(segmentBodyIntersection({ x: 2, y: 1, z: 0 }, { x: 2, y: 1, z: 0 }, feet, 0.5, 2), null);
});
test('nearest living enemy absorbs the shot regardless of placement order', () => {
    const enemies = [
        { id: 'far', type: 'imp', health: 52, position: { x: 8, y: 0, z: 0 } },
        { id: 'dead', type: 'warden', health: 0, position: { x: 1, y: 0, z: 0 } },
        { id: 'near', type: 'acolyte', health: 10, position: { x: 3, y: 0, z: 0 } },
    ];
    assert.equal(nearestEnemyHit({ x: 0, y: 1, z: 0 }, { x: 12, y: 1, z: 0 }, enemies).enemy.id, 'near');
    assert.equal(nearestEnemyHit({ x: 0, y: 4, z: 0 }, { x: 12, y: 4, z: 0 }, enemies), null);
});
