const VERTICAL_CORRIDOR = 'vertical-corridor';

export function tileAtWorldPosition(x, z, grid, width, height, tileSize) {
    const tileX = Math.floor(x / tileSize + width / 2 + 0.5);
    const tileY = Math.floor(z / tileSize + height / 2 + 0.5);

    return {
        x: tileX,
        y: tileY,
        cell: grid[tileY]?.[tileX],
    };
}

export function getRampCells(grid) {
    const ramps = [];
    grid.forEach((row, y) => {
        row?.forEach((cell, x) => {
            if (cell?.type === VERTICAL_CORRIDOR) {
                ramps.push({ x, y, cell });
            }
        });
    });

    return ramps;
}

/**
 * Returns whether two adjacent grid cells are connected by a walkable floor.
 * A vertical corridor is only connected along its own axis: its side walls
 * are real geometry even when the neighboring grid cell is walkable.
 */
export function canTraverseTiles(grid, from, to) {
    if (Math.abs(from.x - to.x) + Math.abs(from.y - to.y) !== 1) {
        return false;
    }

    const fromCell = grid[from.y]?.[from.x];
    const toCell = grid[to.y]?.[to.x];
    if (!fromCell?.walkable || !toCell?.walkable) {
        return false;
    }

    const corridorCell = fromCell.type === VERTICAL_CORRIDOR
        ? fromCell
        : toCell.type === VERTICAL_CORRIDOR
            ? toCell
            : null;

    if (corridorCell) {
        const deltaX = to.x - from.x;
        const deltaY = to.y - from.y;
        const direction = corridorCell.direction || { x: 0, y: 0 };
        const lateralDelta = deltaX * direction.y - deltaY * direction.x;
        if (lateralDelta !== 0) {
            return false;
        }
    }

    return fromCell.floor === toCell.floor ||
        fromCell.type === VERTICAL_CORRIDOR ||
        toCell.type === VERTICAL_CORRIDOR;
}

export function rampSidePenetration(x, z, ramps, width, height, tileSize, radius, wallThickness = 0.28) {
    let penetration = 0;
    const innerWallEdge = tileSize / 2 - wallThickness;

    for (const ramp of ramps) {
        const centerX = (ramp.x - width / 2) * tileSize;
        const centerZ = (ramp.y - height / 2) * tileSize;
        const relativeX = x - centerX;
        const relativeZ = z - centerZ;
        const direction = ramp.cell.direction || { x: 0, y: 0 };
        const along = relativeX * direction.x + relativeZ * direction.y;
        const across = Math.abs(relativeX * -direction.y + relativeZ * direction.x);

        if (Math.abs(along) > tileSize / 2 + radius) {
            continue;
        }

        const overlap = across + radius - innerWallEdge;
        if (overlap > 0 && across < tileSize / 2 + wallThickness + radius) {
            penetration = Math.max(penetration, overlap);
        }
    }

    return penetration;
}

export function isWalkableWorldPosition({
    x,
    z,
    grid,
    width,
    height,
    tileSize,
    radius,
    ramps = [],
    fromTile = null,
    fromPosition = null,
}) {
    const nextRampPenetration = rampSidePenetration(x, z, ramps, width, height, tileSize, radius);
    const currentRampPenetration = fromPosition
        ? rampSidePenetration(fromPosition.x, fromPosition.z, ramps, width, height, tileSize, radius)
        : 0;

    // Allow an entity already overlapping a wall to move back out, but never
    // allow a new step to increase its penetration into the wall.
    if (nextRampPenetration > 0 && nextRampPenetration >= currentRampPenetration) {
        return false;
    }

    const destinationTile = tileAtWorldPosition(x, z, grid, width, height, tileSize);
    if (!destinationTile.cell?.walkable) {
        return false;
    }

    // Apply directional corridor rules to the entity's center movement only.
    // The footprint samples below may touch diagonal tiles at room corners;
    // treating those samples as movement would create false collision spots.
    if (fromTile?.cell &&
        (destinationTile.x !== fromTile.x || destinationTile.y !== fromTile.y) &&
        !canTraverseTiles(grid, fromTile, destinationTile)) {
        return false;
    }

    const samples = [
        { x, z },
        { x: x - radius, z: z - radius },
        { x: x + radius, z: z - radius },
        { x: x - radius, z: z + radius },
        { x: x + radius, z: z + radius },
    ];

    return samples.every((sample) => {
        const tile = tileAtWorldPosition(sample.x, sample.z, grid, width, height, tileSize);
        const cell = tile.cell;
        if (!cell?.walkable) {
            return false;
        }

        if (!fromTile?.cell) {
            return true;
        }

        return cell.floor === fromTile.cell.floor ||
            cell.type === VERTICAL_CORRIDOR ||
            fromTile.cell.type === VERTICAL_CORRIDOR;
    });
}

export function surfaceElevation(x, z, grid, width, height, tileSize) {
    const tile = tileAtWorldPosition(x, z, grid, width, height, tileSize);
    const cell = tile.cell;
    if (!cell?.walkable) {
        return null;
    }

    if (cell.type !== VERTICAL_CORRIDOR) {
        return cell.elevation;
    }

    const centerX = (tile.x - width / 2) * tileSize;
    const centerZ = (tile.y - height / 2) * tileSize;
    const direction = cell.direction || { x: 0, y: 0 };
    const alongSlope = (x - centerX) * direction.x + (z - centerZ) * direction.y;
    return cell.elevation + Math.tan(cell.slope * Math.PI / 180) * alongSlope;
}
