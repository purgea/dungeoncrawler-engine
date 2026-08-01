export class GridPathfinder {
    constructor(grid, width, height) {
        this.grid = grid;
        this.width = width;
        this.height = height;
    }

    cellAt(tile) {
        return this.grid[tile.y]?.[tile.x] ?? null;
    }

    isWalkable(tile) {
        return Boolean(this.cellAt(tile)?.walkable);
    }

    canTraverse(from, to) {
        if (Math.abs(from.x - to.x) + Math.abs(from.y - to.y) !== 1) {
            return false;
        }

        const fromCell = this.cellAt(from);
        const toCell = this.cellAt(to);
        if (!fromCell?.walkable || !toCell?.walkable) {
            return false;
        }

        return fromCell.floor === toCell.floor ||
            fromCell.type === 'vertical-corridor' ||
            toCell.type === 'vertical-corridor';
    }

    neighbors(tile) {
        return [
            { x: tile.x + 1, y: tile.y },
            { x: tile.x - 1, y: tile.y },
            { x: tile.x, y: tile.y + 1 },
            { x: tile.x, y: tile.y - 1 },
        ].filter((neighbor) => (
            neighbor.x >= 0 &&
            neighbor.x < this.width &&
            neighbor.y >= 0 &&
            neighbor.y < this.height &&
            this.canTraverse(tile, neighbor)
        ));
    }

    heuristic(from, to) {
        return Math.abs(from.x - to.x) + Math.abs(from.y - to.y);
    }

    key(tile) {
        return `${tile.x}:${tile.y}`;
    }

    findPath(start, goal) {
        if (!this.isWalkable(start) || !this.isWalkable(goal)) {
            return [];
        }
        if (start.x === goal.x && start.y === goal.y) {
            return [{ x: start.x, y: start.y }];
        }

        const open = [{ ...start }];
        const openKeys = new Set([this.key(start)]);
        const cameFrom = new Map();
        const costSoFar = new Map([[this.key(start), 0]]);

        while (open.length > 0) {
            let bestIndex = 0;
            let bestScore = Infinity;
            for (let index = 0; index < open.length; index += 1) {
                const candidate = open[index];
                const candidateCost = costSoFar.get(this.key(candidate)) ?? Infinity;
                const score = candidateCost + this.heuristic(candidate, goal);
                if (score < bestScore) {
                    bestIndex = index;
                    bestScore = score;
                }
            }

            const current = open.splice(bestIndex, 1)[0];
            const currentKey = this.key(current);
            openKeys.delete(currentKey);

            if (current.x === goal.x && current.y === goal.y) {
                return this.reconstructPath(cameFrom, current);
            }

            for (const neighbor of this.neighbors(current)) {
                const neighborKey = this.key(neighbor);
                const nextCost = (costSoFar.get(currentKey) ?? Infinity) + 1;
                if (nextCost >= (costSoFar.get(neighborKey) ?? Infinity)) {
                    continue;
                }

                cameFrom.set(neighborKey, current);
                costSoFar.set(neighborKey, nextCost);
                if (!openKeys.has(neighborKey)) {
                    open.push(neighbor);
                    openKeys.add(neighborKey);
                }
            }
        }

        return [];
    }

    reconstructPath(cameFrom, end) {
        const path = [{ x: end.x, y: end.y }];
        let current = end;

        while (cameFrom.has(this.key(current))) {
            current = cameFrom.get(this.key(current));
            path.push({ x: current.x, y: current.y });
        }

        return path.reverse();
    }
}
