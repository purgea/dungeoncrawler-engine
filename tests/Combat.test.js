import test from 'node:test';
import assert from 'node:assert/strict';
import { pointOnSegment, segmentSphereIntersection, traceDungeonSegment } from '../resources/js/game/Combat.js';

function room(width = 5, height = 3) {
    return { width, height, tileSize: 4, wallHeight: 3.3, grid: Array.from({ length: height }, () => Array.from({ length: width }, () => ({ walkable: true, floor: 0, elevation: 0, type: 'room' }))) };
}
const at = (dungeon, x, z, y = 1.5) => ({ x: (x - dungeon.width / 2) * dungeon.tileSize, y, z: (z - dungeon.height / 2) * dungeon.tileSize });
const close = (actual, expected) => assert.ok(Math.abs(actual - expected) < 1e-7, `${actual} should equal ${expected}`);

test('a clear segment crosses any number of connected cells without phantom walls', () => {
    const dungeon = room();
    assert.equal(traceDungeonSegment(at(dungeon, 0, 1), at(dungeon, 4, 1), dungeon, 0.1), null);
    assert.deepEqual(pointOnSegment({ x: 0, y: 1, z: 2 }, { x: 4, y: 3, z: 6 }, 0.5), { x: 2, y: 2, z: 4 });
});

test('fast projectiles hit a wall between two open endpoints, accounting for projectile radius', () => {
    const dungeon = room();
    dungeon.grid[1][2].walkable = false;
    const hit = traceDungeonSegment(at(dungeon, 1, 1), at(dungeon, 3, 1), dungeon, 0.1);
    assert.equal(hit.type, 'wall');
    close(hit.position.x, -4.24);
    close(hit.t, 0.22);
    // Reversing the ray must hit the other face, not the original near face.
    close(traceDungeonSegment(at(dungeon, 3, 1), at(dungeon, 1, 1), dungeon, 0.1).position.x, 0.24);
});

test('floor and ceiling sweeps hit their exact contact heights', () => {
    const dungeon = room();
    const from = at(dungeon, 2, 1);
    const floor = traceDungeonSegment(from, { ...from, y: -3 }, dungeon, 0.1);
    assert.equal(floor.type, 'floor');
    close(floor.position.y, 0.1);
    const ceiling = traceDungeonSegment(from, { ...from, y: 8 }, dungeon, 0.1);
    assert.equal(ceiling.type, 'ceiling');
    close(ceiling.position.y, 3.2);
});

test('different storeys and ramp side walls block shots even when both cells are walkable', () => {
    const dungeon = room();
    dungeon.grid[1][2].floor = 3;
    dungeon.grid[1][2].elevation = 3;
    assert.equal(traceDungeonSegment(at(dungeon, 1, 1), at(dungeon, 2, 1), dungeon).type, 'wall');
    dungeon.grid[1][2] = { walkable: true, floor: 0, elevation: 0, type: 'vertical-corridor', slope: 0, direction: { x: 1, y: 0 } };
    const side = traceDungeonSegment(at(dungeon, 2, 0), at(dungeon, 2, 1), dungeon);
    assert.equal(side.type, 'wall');
    assert.equal(traceDungeonSegment(at(dungeon, 1, 1), at(dungeon, 3, 1), dungeon), null);
});

test('a sloped ceiling and floor follow ramp elevation while connected landings stay open', () => {
    const dungeon = room(3, 1);
    dungeon.grid[0][1] = { walkable: true, floor: 0, elevation: 1, type: 'vertical-corridor', slope: Math.atan(0.5) * 180 / Math.PI, direction: { x: 1, y: 0 } };
    dungeon.grid[0][2] = { walkable: true, floor: 2, elevation: 2, type: 'room' };
    assert.equal(traceDungeonSegment(at(dungeon, 0, 0, 1.3), at(dungeon, 2, 0, 3.3), dungeon, 0.1), null);
    const hit = traceDungeonSegment({ x: -3.9, y: 1, z: -2 }, { x: -0.1, y: 1, z: -2 }, dungeon, 0.1);
    assert.equal(hit.type, 'floor');
    close(hit.position.x, -2.2);
});

test('world borders and starting in solid geometry cannot leak projectiles', () => {
    const dungeon = room();
    const from = at(dungeon, 0, 1);
    const hit = traceDungeonSegment(from, { ...from, x: -100 }, dungeon, 0.1);
    assert.equal(hit.type, 'wall');
    close(hit.position.x, -12 + 0.24);
    assert.equal(traceDungeonSegment({ x: -100, y: 1, z: 0 }, from, dungeon).t, 0);
});

test('sphere sweeps detect a hit between endpoints and reject vertical misses', () => {
    const from = { x: 0, y: 1, z: 0 };
    const to = { x: 10, y: 1, z: 0 };
    close(segmentSphereIntersection(from, to, { x: 5, y: 1, z: 0 }, 1), 0.4);
    assert.equal(segmentSphereIntersection(from, to, { x: 5, y: 4, z: 0 }, 1), null);
    assert.equal(segmentSphereIntersection(from, from, from, 1), 0);
    assert.equal(segmentSphereIntersection(from, from, { x: 5, y: 1, z: 0 }, 1), null);
});
