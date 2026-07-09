<script setup>
import * as pc from 'playcanvas';

const dungeonWidth = 63;
const dungeonHeight = 63;
const dungeonFloors = 3;
const tileSize = 4;
const wallHeight = 3.3;

function randomInt(min, max) {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}

function intersects(a, b) {
    if (a.floor !== b.floor) {
        return false;
    }

    return !(
        a.x + a.w + 1 < b.x ||
        b.x + b.w + 1 < a.x ||
        a.y + a.h + 1 < b.y ||
        b.y + b.h + 1 < a.y
    );
}

function carveRoom(grid, room) {
    for (let y = room.y; y < room.y + room.h; y += 1) {
        for (let x = room.x; x < room.x + room.w; x += 1) {
            grid[room.floor][y][x] = 1;
        }
    }
}

function carveCorridor(grid, from, to) {
    const horizontalFirst = Math.random() > 0.5;
    const carveX = (x1, x2, y) => {
        for (let x = Math.min(x1, x2); x <= Math.max(x1, x2); x += 1) {
            grid[from.floor][y][x] = 1;
        }
    };
    const carveY = (y1, y2, x) => {
        for (let y = Math.min(y1, y2); y <= Math.max(y1, y2); y += 1) {
            grid[from.floor][y][x] = 1;
        }
    };

    if (horizontalFirst) {
        carveX(from.x, to.x, from.y);
        carveY(from.y, to.y, to.x);
    } else {
        carveY(from.y, to.y, from.x);
        carveX(from.x, to.x, to.y);
    }
}

function carveStairs(grid, fromRoom, toRoom) {
    const from = {
        floor: fromRoom.floor,
        x: Math.floor(fromRoom.x + fromRoom.w / 2),
        y: Math.floor(fromRoom.y + fromRoom.h / 2),
    };
    const to = {
        floor: toRoom.floor,
        x: Math.floor(toRoom.x + toRoom.w / 2),
        y: Math.floor(toRoom.y + toRoom.h / 2),
    };

    grid[from.floor][from.y][from.x] = 2;
    grid[to.floor][to.y][to.x] = 2;

    return { from, to };
}

function generateDungeon() {
    const grid = Array.from({ length: dungeonFloors }, () =>
        Array.from({ length: dungeonHeight }, () => Array(dungeonWidth).fill(0)),
    );
    const rooms = [];

    for (let floor = 0; floor < dungeonFloors; floor += 1) {
        for (let attempt = 0; attempt < 140 && rooms.filter((room) => room.floor === floor).length < 8; attempt += 1) {
            const room = {
                floor,
                w: randomInt(4, 10),
                h: randomInt(4, 10),
                x: randomInt(2, dungeonWidth - 12),
                y: randomInt(2, dungeonHeight - 12),
            };

            if (rooms.some((existing) => intersects(room, existing))) {
                continue;
            }

            carveRoom(grid, room);
            rooms.push(room);
        }
    }

    if (!rooms.length) {
        const room = {
            floor: 0,
            x: 8,
            y: 8,
            w: 10,
            h: 10,
        };
        carveRoom(grid, room);
        rooms.push(room);
    }

    rooms
        .filter((room) => room.floor === 0)
        .map((room) => ({
            floor: room.floor,
            x: Math.floor(room.x + room.w / 2),
            y: Math.floor(room.y + room.h / 2),
        }))
        .forEach((center, index, centers) => {
            if (index > 0) {
                carveCorridor(grid, centers[index - 1], center);
            }
        });

    for (let floor = 0; floor < dungeonFloors - 1; floor += 1) {
        const floorRooms = rooms.filter((room) => room.floor === floor);
        const nextFloorRooms = rooms.filter((room) => room.floor === floor + 1);
        if (floorRooms.length && nextFloorRooms.length) {
            carveStairs(grid, floorRooms[0], nextFloorRooms[0]);
        }
    }

    const startRoom = rooms.find((room) => room.floor === 0) || rooms[0];
    const spawn = {
        x: Math.floor(startRoom.x + startRoom.w / 2),
        y: Math.floor(startRoom.y + startRoom.h / 2),
        floor: startRoom.floor,
    };
    const candidates = [];

    for (let x = startRoom.x; x < startRoom.x + startRoom.w; x += 1) {
        candidates.push({ x, y: startRoom.y, dx: 0, dy: -1 });
        candidates.push({ x, y: startRoom.y + startRoom.h - 1, dx: 0, dy: 1 });
    }

    for (let y = startRoom.y + 1; y < startRoom.y + startRoom.h - 1; y += 1) {
        candidates.push({ x: startRoom.x, y, dx: -1, dy: 0 });
        candidates.push({ x: startRoom.x + startRoom.w - 1, y, dx: 1, dy: 0 });
    }

    const door = candidates.reduce((best, candidate) => {
        const bestDistance = Math.hypot(best.x - spawn.x, best.y - spawn.y);
        const candidateDistance = Math.hypot(candidate.x - spawn.x, candidate.y - spawn.y);
        return candidateDistance < bestDistance ? candidate : best;
    }, candidates[0]);

    return { grid, startRoom, door: { ...door, floor: startRoom.floor }, spawn };
}

function worldPosition(x, y, floor = 0) {
    return {
        x: (x - dungeonWidth / 2) * tileSize,
        y: floor * (wallHeight + 0.4),
        z: (y - dungeonHeight / 2) * tileSize,
    };
}

function createDungeonTexture(appInstance) {
    const canvas = document.createElement('canvas');
    canvas.width = 128;
    canvas.height = 128;
    const ctx = canvas.getContext('2d');
    const baseHue = randomInt(20, 210);

    ctx.fillStyle = `hsl(${baseHue} 20% 36%)`;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    for (let y = 0; y < canvas.height; y += 8) {
        for (let x = 0; x < canvas.width; x += 8) {
            const lightness = randomInt(28, 54);
            ctx.fillStyle = `hsl(${baseHue + randomInt(-10, 10)} 18% ${lightness}%)`;
            ctx.fillRect(x, y, 8, 8);
        }
    }

    ctx.strokeStyle = 'rgb(20 22 20 / 0.45)';
    ctx.lineWidth = 2;
    for (let line = 0; line <= 128; line += 32) {
        ctx.beginPath();
        ctx.moveTo(line, 0);
        ctx.lineTo(line, 128);
        ctx.moveTo(0, line);
        ctx.lineTo(128, line);
        ctx.stroke();
    }

    const texture = new pc.Texture(appInstance.graphicsDevice, {
        width: canvas.width,
        height: canvas.height,
        format: pc.PIXELFORMAT_R8_G8_B8_A8,
        mipmaps: true,
    });
    texture.addressU = pc.ADDRESS_REPEAT;
    texture.addressV = pc.ADDRESS_REPEAT;
    texture.minFilter = pc.FILTER_LINEAR_MIPMAP_LINEAR;
    texture.magFilter = pc.FILTER_LINEAR;
    texture.setSource(canvas);

    return texture;
}

function createDoorTexture(appInstance) {
    const canvas = document.createElement('canvas');
    canvas.width = 128;
    canvas.height = 128;
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = '#3c2a18';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = 'rgba(18, 12, 8, 0.9)';
    ctx.fillRect(20, 12, 88, 104);
    ctx.fillStyle = 'rgba(104, 74, 35, 0.98)';
    ctx.fillRect(28, 20, 72, 88);
    ctx.fillStyle = 'rgba(36, 22, 12, 0.98)';
    ctx.fillRect(39, 30, 50, 68);
    ctx.fillStyle = 'rgba(190, 167, 108, 0.55)';
    ctx.fillRect(59, 54, 10, 10);
    ctx.strokeStyle = 'rgba(221, 194, 134, 0.52)';
    ctx.lineWidth = 3;
    ctx.strokeRect(24, 16, 80, 96);
    ctx.strokeStyle = 'rgba(64, 42, 22, 0.95)';
    ctx.lineWidth = 2;
    ctx.strokeRect(37, 28, 54, 72);

    const texture = new pc.Texture(appInstance.graphicsDevice, {
        width: canvas.width,
        height: canvas.height,
        format: pc.PIXELFORMAT_R8_G8_B8_A8,
        mipmaps: true,
    });
    texture.addressU = pc.ADDRESS_CLAMP_TO_EDGE;
    texture.addressV = pc.ADDRESS_CLAMP_TO_EDGE;
    texture.minFilter = pc.FILTER_LINEAR_MIPMAP_LINEAR;
    texture.magFilter = pc.FILTER_LINEAR;
    texture.setSource(canvas);

    return texture;
}

function createMaterial(texture) {
    const material = new pc.StandardMaterial();
    material.diffuseMap = texture;
    material.diffuseMapTiling.set(1, 1);
    material.diffuse.set(0.95, 0.92, 0.82);
    material.ambient.set(0.55, 0.52, 0.46);
    material.update();

    return material;
}

function createDoorMaterial(texture) {
    const material = new pc.StandardMaterial();
    material.diffuseMap = texture;
    material.diffuseMapTiling.set(1, 1);
    material.diffuse.set(0.62, 0.49, 0.24);
    material.ambient.set(0.26, 0.2, 0.12);
    material.emissive.set(0.08, 0.05, 0.02);
    material.update();

    return material;
}

function createRecessMaterial(texture) {
    const material = new pc.StandardMaterial();
    material.diffuseMap = texture;
    material.diffuseMapTiling.set(1, 1);
    material.diffuse.set(0.16, 0.12, 0.08);
    material.ambient.set(0.08, 0.06, 0.04);
    material.update();

    return material;
}

function createArchMaterial(texture) {
    const material = new pc.StandardMaterial();
    material.diffuseMap = texture;
    material.diffuseMapTiling.set(1, 1);
    material.diffuse.set(0.58, 0.54, 0.48);
    material.ambient.set(0.28, 0.25, 0.22);
    material.update();

    return material;
}

function addBox(appInstance, name, position, scale, material) {
    const entity = new pc.Entity(name);
    entity.addComponent('render', {
        type: 'box',
        material,
    });
    entity.setLocalPosition(position.x, position.y, position.z);
    entity.setLocalScale(scale.x, scale.y, scale.z);
    appInstance.root.addChild(entity);

    return entity;
}

function buildDungeon(appInstance, grid, material, door, doorMaterial, recessMaterial, archMaterial) {
    const floorThickness = 0.16;
    const wallThickness = 0.28;

    for (let floor = 0; floor < dungeonFloors; floor += 1) {
        const elevation = floor * (wallHeight + 0.4);

        for (let y = 0; y < dungeonHeight; y += 1) {
            for (let x = 0; x < dungeonWidth; x += 1) {
                if (grid[floor][y][x] === 0) {
                    continue;
                }

                const pos = worldPosition(x, y, floor);
                addBox(appInstance, 'floor', { x: pos.x, y: elevation - floorThickness / 2, z: pos.z }, { x: tileSize, y: floorThickness, z: tileSize }, material);
                addBox(appInstance, 'ceiling', { x: pos.x, y: elevation + wallHeight, z: pos.z }, { x: tileSize, y: floorThickness, z: tileSize }, material);

                if (grid[floor][y][x] === 2) {
                    addBox(
                        appInstance,
                        'stairs-marker',
                        { x: pos.x, y: elevation + 0.18, z: pos.z },
                        { x: tileSize * 0.5, y: 0.36, z: tileSize * 0.5 },
                        archMaterial || material,
                    );
                    continue;
                }

                if (door && floor === door.floor && x === door.x && y === door.y) {
                    const doorOffset = {
                        x: door.dx === 1 ? tileSize / 2 : door.dx === -1 ? -tileSize / 2 : 0,
                        z: door.dy === 1 ? tileSize / 2 : door.dy === -1 ? -tileSize / 2 : 0,
                    };
                    addBox(
                        appInstance,
                        'start-door-wall',
                        { x: pos.x + doorOffset.x, y: elevation + wallHeight / 2, z: pos.z + doorOffset.z },
                        {
                            x: door.dx === 0 ? tileSize : wallThickness,
                            y: wallHeight,
                            z: door.dy === 0 ? tileSize : wallThickness,
                        },
                        material,
                    );
                    addBox(
                        appInstance,
                        'start-door-recess',
                        { x: pos.x + doorOffset.x * 0.92, y: elevation + wallHeight / 2, z: pos.z + doorOffset.z * 0.92 },
                        {
                            x: door.dx === 0 ? tileSize * 0.5 : wallThickness * 0.82,
                            y: wallHeight * 0.84,
                            z: door.dy === 0 ? tileSize * 0.5 : wallThickness * 0.82,
                        },
                        recessMaterial || material,
                    );
                    addBox(
                        appInstance,
                        'start-door-panel',
                        { x: pos.x + doorOffset.x * 0.975, y: elevation + wallHeight / 2, z: pos.z + doorOffset.z * 0.975 },
                        {
                            x: door.dx === 0 ? tileSize * 0.42 : wallThickness * 0.62,
                            y: wallHeight * 0.78,
                            z: door.dy === 0 ? tileSize * 0.42 : wallThickness * 0.62,
                        },
                        doorMaterial || material,
                    );
                    addBox(
                        appInstance,
                        'start-door-arch-left',
                        {
                            x: pos.x + (door.dx !== 0 ? doorOffset.x : -tileSize * 0.28),
                            y: elevation + wallHeight * 0.58,
                            z: pos.z + (door.dy !== 0 ? doorOffset.z : -tileSize * 0.28),
                        },
                        {
                            x: door.dx !== 0 ? wallThickness * 0.55 : tileSize * 0.16,
                            y: wallHeight * 0.72,
                            z: door.dy !== 0 ? wallThickness * 0.55 : tileSize * 0.16,
                        },
                        archMaterial || material,
                    );
                    addBox(
                        appInstance,
                        'start-door-arch-right',
                        {
                            x: pos.x + (door.dx !== 0 ? doorOffset.x : tileSize * 0.28),
                            y: elevation + wallHeight * 0.58,
                            z: pos.z + (door.dy !== 0 ? doorOffset.z : tileSize * 0.28),
                        },
                        {
                            x: door.dx !== 0 ? wallThickness * 0.55 : tileSize * 0.16,
                            y: wallHeight * 0.72,
                            z: door.dy !== 0 ? wallThickness * 0.55 : tileSize * 0.16,
                        },
                        archMaterial || material,
                    );
                    addBox(
                        appInstance,
                        'start-door-arch-top',
                        {
                            x: pos.x + doorOffset.x,
                            y: elevation + wallHeight * 0.9,
                            z: pos.z + doorOffset.z,
                        },
                        {
                            x: door.dx === 0 ? tileSize * 0.88 : wallThickness * 1.45,
                            y: wallHeight * 0.18,
                            z: door.dy === 0 ? tileSize * 0.88 : wallThickness * 1.45,
                        },
                        archMaterial || material,
                    );
                    continue;
                }

                [
                    { dx: 0, dy: -1, px: 0, pz: -tileSize / 2, sx: tileSize, sz: wallThickness },
                    { dx: 0, dy: 1, px: 0, pz: tileSize / 2, sx: tileSize, sz: wallThickness },
                    { dx: -1, dy: 0, px: -tileSize / 2, pz: 0, sx: wallThickness, sz: tileSize },
                    { dx: 1, dy: 0, px: tileSize / 2, pz: 0, sx: wallThickness, sz: tileSize },
                ].forEach((edge) => {
                    if (grid[floor][y + edge.dy]?.[x + edge.dx] !== 1 && grid[floor][y + edge.dy]?.[x + edge.dx] !== 2) {
                        addBox(
                            appInstance,
                            'wall',
                            { x: pos.x + edge.px, y: elevation + wallHeight / 2, z: pos.z + edge.pz },
                            { x: edge.sx, y: wallHeight, z: edge.sz },
                            material,
                        );
                    }
                });
            }
        }
    }

    return;
}

function findRandomFloorTile(collisionGrid, exclude = []) {
    const candidates = [];

    for (let floor = 0; floor < dungeonFloors; floor += 1) {
        for (let y = 1; y < dungeonHeight - 1; y += 1) {
            for (let x = 1; x < dungeonWidth - 1; x += 1) {
                if (collisionGrid[floor]?.[y]?.[x] !== 1) {
                    continue;
                }

                if (exclude.some((point) => point.floor === floor && point.x === x && point.y === y)) {
                    continue;
                }

                candidates.push({ floor, x, y });
            }
        }
    }

    return candidates[randomInt(0, candidates.length - 1)];
}

defineExpose({
    dungeonWidth,
    dungeonHeight,
    tileSize,
    wallHeight,
    generateDungeon,
    createDungeonTexture,
    createDoorTexture,
    createMaterial,
    createDoorMaterial,
    createRecessMaterial,
    createArchMaterial,
    buildDungeon,
    findRandomFloorTile,
    worldPosition,
});
</script>

<template>
    <div hidden />
</template>
