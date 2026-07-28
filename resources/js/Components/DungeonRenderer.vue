<script setup>
import * as pc from 'playcanvas';

const props = defineProps({
    layout: {
        type: Object,
        required: true,
    },
});

const dungeonWidth = props.layout.width;
const dungeonHeight = props.layout.height;
const floorElevations = props.layout.floors;
const tileSize = props.layout.tileSize;
const wallHeight = props.layout.wallHeight;
const decorationEntities = [];

function randomInt(min, max) {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}

function worldPosition(x, y, floor = 0) {
    return {
        x: (x - dungeonWidth / 2) * tileSize,
        y: floor,
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
    material.ambient.set(0.24, 0.22, 0.19);
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

function addBox(appInstance, name, position, scale, material, rotation = null) {
    const entity = new pc.Entity(name);
    entity.addComponent('render', {
        type: 'box',
        material,
    });
    entity.setLocalPosition(position.x, position.y, position.z);
    entity.setLocalScale(scale.x, scale.y, scale.z);
    if (rotation) {
        entity.setLocalEulerAngles(rotation.x || 0, rotation.y || 0, rotation.z || 0);
    }
    appInstance.root.addChild(entity);

    return entity;
}

function createTorchMaterials() {
    const metal = new pc.StandardMaterial();
    metal.diffuse.set(0.12, 0.09, 0.06);
    metal.metalness = 0.65;
    metal.shininess = 70;
    metal.update();

    const wood = new pc.StandardMaterial();
    wood.diffuse.set(0.2, 0.09, 0.035);
    wood.ambient.set(0.08, 0.035, 0.015);
    wood.update();

    const flame = new pc.StandardMaterial();
    flame.diffuse.set(1, 0.3, 0.025);
    flame.emissive.set(1, 0.18, 0.015);
    flame.emissiveIntensity = 4;
    flame.update();

    return { metal, wood, flame };
}

function addTorch(appInstance, position, edge, materials) {
    const torch = new pc.Entity('wall-torch');
    torch.setLocalPosition(position.x, position.y, position.z);

    const bracket = new pc.Entity('torch-bracket');
    bracket.addComponent('render', { type: 'box', material: materials.metal });
    bracket.setLocalScale(edge.dx === 0 ? 0.26 : 0.46, 0.16, edge.dy === 0 ? 0.26 : 0.46);
    torch.addChild(bracket);

    const handle = new pc.Entity('torch-handle');
    handle.addComponent('render', { type: 'cylinder', material: materials.wood });
    handle.setLocalPosition(-edge.dx * 0.28, 0.28, -edge.dy * 0.28);
    handle.setLocalScale(0.14, 0.68, 0.14);
    handle.setLocalEulerAngles(edge.dy * -22, 0, edge.dx * 22);
    torch.addChild(handle);

    const flame = new pc.Entity('torch-flame');
    flame.addComponent('render', { type: 'sphere', material: materials.flame });
    flame.setLocalPosition(-edge.dx * 0.52, 0.78, -edge.dy * 0.52);
    flame.setLocalScale(0.24, 0.42, 0.24);
    torch.addChild(flame);

    const light = new pc.Entity('torch-light');
    light.addComponent('light', {
        type: 'omni',
        color: new pc.Color(1, 0.34, 0.075),
        intensity: 2.15,
        range: 10,
        castShadows: false,
    });
    light.setLocalPosition(-edge.dx * 0.72, 0.75, -edge.dy * 0.72);
    torch.addChild(light);
    appInstance.root.addChild(torch);
}

function loadTexture(appInstance, url) {
    return new Promise((resolve, reject) => {
        const asset = new pc.Asset(`decoration-${url}`, 'texture', { url });
        asset.once('load', () => {
            const texture = asset.resource;
            texture.addressU = pc.ADDRESS_CLAMP_TO_EDGE;
            texture.addressV = pc.ADDRESS_CLAMP_TO_EDGE;
            texture.minFilter = pc.FILTER_LINEAR_MIPMAP_LINEAR;
            texture.magFilter = pc.FILTER_LINEAR;
            resolve({ texture, width: texture.width, height: texture.height });
        });
        asset.once('error', () => reject(new Error(`Unable to load decoration asset: ${url}`)));
        appInstance.assets.add(asset);
        appInstance.assets.load(asset);
    });
}

async function addDecoration(appInstance, decoration) {
    const image = await loadTexture(appInstance, decoration.asset.path_url);
    const material = new pc.StandardMaterial();
    material.diffuseMap = image.texture;
    material.opacityMap = image.texture;
    material.opacityMapChannel = 'a';
    material.diffuse.set(1, 1, 1);
    material.opacity = 1;
    material.alphaTest = 0.05;
    material.blendType = pc.BLEND_NORMAL;
    material.depthWrite = false;
    material.cull = pc.CULLFACE_NONE;
    material.update();

    const decorationHeight = 2;
    const decorationWidth = decorationHeight * image.width / image.height;
    const entity = new pc.Entity(`decoration-${decoration.x}-${decoration.y}`);
    const plane = new pc.Entity(`decoration-sprite-${decoration.x}-${decoration.y}`);
    plane.addComponent('render', { type: 'plane', material });
    const position = worldPosition(decoration.x, decoration.y, decoration.floor);
    entity.setLocalPosition(position.x, decoration.floor + decorationHeight / 2, position.z);
    plane.setLocalScale(decorationWidth, 1, decorationHeight);
    plane.setLocalEulerAngles(90, 0, 0);
    entity.addChild(plane);
    appInstance.root.addChild(entity);
    decorationEntities.push(entity);
}

function updateDecorations(cameraYaw) {
    if (cameraYaw === null || cameraYaw === undefined) {
        return;
    }

    decorationEntities.forEach((entity) => {
        entity.setLocalEulerAngles(0, cameraYaw, 0);
    });
}

async function buildDungeon(appInstance, grid, material, door, doorMaterial, recessMaterial, archMaterial, decorations = []) {
    const floorThickness = 0.16;
    const wallThickness = 0.28;
    const torchMaterials = createTorchMaterials();
    const torchTiles = new Map(floorElevations.map((floor) => [floor, []]));
    const torchCounts = new Map(floorElevations.map((floor) => [floor, 0]));
    const verticalCorridors = [];

    for (let y = 0; y < dungeonHeight; y += 1) {
        for (let x = 0; x < dungeonWidth; x += 1) {
            if (grid[y][x]?.type !== 'vertical-corridor') {
                continue;
            }

            const previousIsSameCorridor = grid[y][x].direction.x
                ? grid[y][x - grid[y][x].direction.x]?.type === 'vertical-corridor'
                : grid[y - grid[y][x].direction.y]?.[x]?.type === 'vertical-corridor';
            if (previousIsSameCorridor) {
                continue;
            }

            const cells = [];
            let cursorX = x;
            let cursorY = y;
            while (grid[cursorY]?.[cursorX]?.type === 'vertical-corridor') {
                cells.push({ x: cursorX, y: cursorY, cell: grid[cursorY][cursorX] });
                cursorX += grid[y][x].direction.x;
                cursorY += grid[y][x].direction.y;
            }
            verticalCorridors.push(cells);
        }
    }

    verticalCorridors.forEach((cells) => {
        const first = cells[0];
        const last = cells[cells.length - 1];
        const direction = first.cell.direction;
        const slopeRadians = first.cell.slope * pc.math.DEG_TO_RAD;
        const horizontalLength = cells.length * tileSize;
        const elevationChange = Math.tan(slopeRadians) * horizontalLength;
        const startElevation = first.cell.elevation - elevationChange / cells.length / 2;
        const endElevation = last.cell.elevation + elevationChange / cells.length / 2;
        const start = worldPosition(first.x, first.y, startElevation);
        const end = worldPosition(last.x, last.y, endElevation);
        const center = {
            x: (start.x + end.x) / 2,
            y: (startElevation + endElevation) / 2,
            z: (start.z + end.z) / 2,
        };
        const slopedLength = Math.hypot(horizontalLength, endElevation - startElevation);
        const rotation = {
            x: direction.y ? -first.cell.slope * direction.y : 0,
            y: 0,
            z: direction.x ? first.cell.slope * direction.x : 0,
        };
        const corridorScale = {
            x: direction.x ? slopedLength : tileSize,
            y: floorThickness,
            z: direction.y ? slopedLength : tileSize,
        };

        addBox(
            appInstance,
            'vertical-corridor-floor',
            { x: center.x, y: center.y - Math.cos(slopeRadians) * floorThickness / 2, z: center.z },
            corridorScale,
            material,
            rotation,
        );
        addBox(
            appInstance,
            'vertical-corridor-ceiling',
            { x: center.x, y: center.y + wallHeight + Math.cos(slopeRadians) * floorThickness / 2, z: center.z },
            corridorScale,
            material,
            rotation,
        );

        const risePerTile = Math.abs(elevationChange / cells.length);
        cells.forEach(({ x, y, cell }) => {
            const section = worldPosition(x, y, cell.elevation);
            const sectionHeight = wallHeight + risePerTile + 0.16;

            [
                { x: -direction.y * tileSize / 2, z: direction.x * tileSize / 2 },
                { x: direction.y * tileSize / 2, z: -direction.x * tileSize / 2 },
            ].forEach((offset) => {
                addBox(
                    appInstance,
                    'vertical-corridor-wall-infill',
                    {
                        x: section.x + offset.x,
                        y: cell.elevation + wallHeight / 2,
                        z: section.z + offset.z,
                    },
                    {
                        x: direction.x ? tileSize : wallThickness * 1.8,
                        y: sectionHeight,
                        z: direction.y ? tileSize : wallThickness * 1.8,
                    },
                    material,
                );
            });
        });
    });

    for (let y = 0; y < dungeonHeight; y += 1) {
        for (let x = 0; x < dungeonWidth; x += 1) {
            const cell = grid[y][x];
            if (!cell?.walkable) {
                continue;
            }
            if (cell.type === 'vertical-corridor') {
                continue;
            }

            const elevation = cell.elevation;
            const pos = worldPosition(x, y, elevation);
            const rotation = null;
            const slopeRadians = 0;
            const tileScale = { x: tileSize, y: floorThickness, z: tileSize };
            addBox(
                appInstance,
                cell.type,
                { x: pos.x, y: elevation - Math.cos(slopeRadians) * floorThickness / 2, z: pos.z },
                tileScale,
                material,
                rotation,
            );
            addBox(
                appInstance,
                'ceiling',
                { x: pos.x, y: elevation + wallHeight + Math.cos(slopeRadians) * floorThickness / 2, z: pos.z },
                tileScale,
                material,
                rotation,
            );

            if (door && cell.floor === door.floor && x === door.x && y === door.y) {
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
                    const neighbor = grid[y + edge.dy]?.[x + edge.dx];
                    const connectsToNeighbor = neighbor?.walkable && (
                        neighbor.floor === cell.floor ||
                        cell.type === 'vertical-corridor' ||
                        neighbor.type === 'vertical-corridor'
                    );
                    if (!connectsToNeighbor) {
                        addBox(
                            appInstance,
                            'wall',
                            { x: pos.x + edge.px, y: elevation + wallHeight / 2, z: pos.z + edge.pz },
                            { x: edge.sx, y: wallHeight, z: edge.sz },
                            material,
                            null,
                        );

                        const floorTorchTiles = torchTiles.get(cell.floor) || [];
                        const isFarEnough = floorTorchTiles.every(
                            (tile) => Math.abs(tile.x - x) + Math.abs(tile.y - y) >= 3,
                        );
                        const floorTorchCount = torchCounts.get(cell.floor) || 0;
                        if (cell.type !== 'vertical-corridor' && floorTorchCount < 18 && isFarEnough && Math.random() < 0.1) {
                            addTorch(
                                appInstance,
                                {
                                    x: pos.x + edge.px - edge.dx * 0.12,
                                    y: elevation + wallHeight * 0.42,
                                    z: pos.z + edge.pz - edge.dy * 0.12,
                                },
                                edge,
                                torchMaterials,
                            );
                            floorTorchTiles.push({ x, y });
                            torchTiles.set(cell.floor, floorTorchTiles);
                            torchCounts.set(cell.floor, floorTorchCount + 1);
                        }
                    }
                });
        }
    }

    await Promise.all(decorations.map((decoration) => (
        decoration.asset?.path_url ? addDecoration(appInstance, decoration) : null
    )));

    return;
}

defineExpose({
    dungeonWidth,
    dungeonHeight,
    tileSize,
    wallHeight,
    createDungeonTexture,
    createDoorTexture,
    createMaterial,
    createDoorMaterial,
    createRecessMaterial,
    createArchMaterial,
    buildDungeon,
    updateDecorations,
    worldPosition,
});
</script>

<template>
    <div hidden />
</template>
