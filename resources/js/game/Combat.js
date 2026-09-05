import { canTraverseTiles } from '../ai/GridCollision.js';

const EPSILON = 1e-8;

export function pointOnSegment(from, to, t) {
    return {
        x: from.x + (to.x - from.x) * t,
        y: from.y + (to.y - from.y) * t,
        z: from.z + (to.z - from.z) * t,
    };
}

/** First contact with a sphere, including a segment starting inside it. */
export function segmentSphereIntersection(from, to, center, radius) {
    const dx = to.x - from.x;
    const dy = to.y - from.y;
    const dz = to.z - from.z;
    const ox = from.x - center.x;
    const oy = from.y - center.y;
    const oz = from.z - center.z;
    const c = ox * ox + oy * oy + oz * oz - radius * radius;
    if (c <= 0) return 0;
    const a = dx * dx + dy * dy + dz * dz;
    if (a < EPSILON) return null;
    const b = ox * dx + oy * dy + oz * dz;
    const discriminant = b * b - a * c;
    if (discriminant < 0) return null;
    const t = (-b - Math.sqrt(discriminant)) / a;
    return t >= 0 && t <= 1 ? t : null;
}

function horizontalInterval(from, to, minX, maxX, minZ, maxZ) {
    let enter = 0;
    let leave = 1;
    for (const [axis, min, max] of [['x', minX, maxX], ['z', minZ, maxZ]]) {
        const delta = to[axis] - from[axis];
        if (Math.abs(delta) < EPSILON) {
            if (from[axis] < min || from[axis] > max) return null;
            continue;
        }
        const a = (min - from[axis]) / delta;
        const b = (max - from[axis]) / delta;
        enter = Math.max(enter, Math.min(a, b));
        leave = Math.min(leave, Math.max(a, b));
        if (enter > leave) return null;
    }
    return [enter, leave];
}

function elevationAt(cell, x, z, centerX, centerZ) {
    const base = Number(cell.elevation) || 0;
    if (cell.type !== 'vertical-corridor') return base;
    const direction = cell.direction || { x: 0, y: 0 };
    const along = (x - centerX) * direction.x + (z - centerZ) * direction.y;
    return base + Math.tan((cell.slope || 0) * Math.PI / 180) * along;
}

/**
 * Sweep a projectile through the actual dungeon volume. Cell slabs are tested
 * analytically, so a fast bolt cannot skip thin walls, ramps, floors or ceilings.
 * Returns null when clear, otherwise { t, position, type, tile } at first contact.
 */
export function traceDungeonSegment(from, to, dungeon, radius = 0) {
    const { grid = [], width = 0, height = 0, tileSize = 1, wallHeight = 3.3 } = dungeon;
    if (!width || !height || !(tileSize > 0)) return null;
    radius = Math.max(0, radius);
    const half = tileSize / 2;
    const padding = radius + 0.26;
    const tileX = (x) => Math.floor(x / tileSize + width / 2 + 0.5);
    const tileY = (z) => Math.floor(z / tileSize + height / 2 + 0.5);
    // A one-cell border represents the solid world outside the grid.
    const minX = Math.max(-1, tileX(Math.min(from.x, to.x) - padding));
    const maxX = Math.min(width, tileX(Math.max(from.x, to.x) + padding));
    const minY = Math.max(-1, tileY(Math.min(from.z, to.z) - padding));
    const maxY = Math.min(height, tileY(Math.max(from.z, to.z) + padding));
    let hit = null;
    const record = (t, type, x, y) => {
        if (t >= 0 && t <= 1 && (!hit || t < hit.t)) {
            hit = { t, position: pointOnSegment(from, to, t), type, tile: { x, y } };
        }
    };

    const originCell = grid[tileY(from.z)]?.[tileX(from.x)];
    if (!originCell?.walkable) return { t: 0, position: { ...from }, type: 'wall', tile: { x: tileX(from.x), y: tileY(from.z) } };

    for (let y = minY; y <= maxY; y += 1) {
        for (let x = minX; x <= maxX; x += 1) {
            const cell = grid[y]?.[x];
            const centerX = (x - width / 2) * tileSize;
            const centerZ = (y - height / 2) * tileSize;
            const left = centerX - half;
            const right = centerX + half;
            const near = centerZ - half;
            const far = centerZ + half;
            if (!cell?.walkable) {
                const interval = horizontalInterval(from, to, left - radius, right + radius, near - radius, far + radius);
                if (interval) record(interval[0], 'wall', x, y);
                continue;
            }

            const interval = horizontalInterval(from, to, left, right, near, far);
            if (interval) {
                const start = pointOnSegment(from, to, interval[0]);
                const end = pointOnSegment(from, to, interval[1]);
                const startHeight = start.y - elevationAt(cell, start.x, start.z, centerX, centerZ);
                const endHeight = end.y - elevationAt(cell, end.x, end.z, centerX, centerZ);
                for (const [type, startGap, endGap] of [
                    ['floor', startHeight - radius, endHeight - radius],
                    ['ceiling', wallHeight - startHeight - radius, wallHeight - endHeight - radius],
                ]) {
                    if (startGap < EPSILON) record(interval[0], type, x, y);
                    else if (endGap <= 0) {
                        record(interval[0] + (interval[1] - interval[0]) * startGap / (startGap - endGap), type, x, y);
                    }
                }
            }

            // Include the renderer's wall thickness, particularly ramp side walls.
            const thickness = cell.type === 'vertical-corridor' ? 0.252 : 0.14;
            for (const [dx, dy] of [[-1, 0], [1, 0], [0, -1], [0, 1]]) {
                if (canTraverseTiles(grid, { x, y }, { x: x + dx, y: y + dy })) continue;
                const edgeX = centerX + dx * half;
                const edgeZ = centerZ + dy * half;
                const wall = horizontalInterval(
                    from, to,
                    dx ? edgeX - thickness - radius : left - radius,
                    dx ? edgeX + thickness + radius : right + radius,
                    dy ? edgeZ - thickness - radius : near - radius,
                    dy ? edgeZ + thickness + radius : far + radius,
                );
                if (wall) record(wall[0], 'wall', x, y);
            }
        }
    }
    return hit;
}
