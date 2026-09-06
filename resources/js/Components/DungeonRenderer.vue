<script setup>
import * as pc from 'playcanvas';
import { colorFromRgb, falloffModeFromName } from '../lighting';
import wallTextureUrl from '../../../extras/image.png';

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
const lighting = props.layout.lighting;
const decorationEntities = [];
const torchLights = [];
let lightingTime = 0;
let staticBatchGroupId = -1;

const DUNGEON_TEXTURE_HUE = 38;
const DUNGEON_TEXTURE_BASE_LIGHTNESS = 36;
const DUNGEON_TEXTURE_LIGHTNESS = 42;
const TORCH_LIGHT_RANGE_SCALE = 0.9;

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
    const baseHue = DUNGEON_TEXTURE_HUE;

    ctx.fillStyle = `hsl(${baseHue} 20% ${DUNGEON_TEXTURE_BASE_LIGHTNESS}%)`;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    for (let y = 0; y < canvas.height; y += 8) {
        for (let x = 0; x < canvas.width; x += 8) {
            ctx.fillStyle = `hsl(${baseHue} 18% ${DUNGEON_TEXTURE_LIGHTNESS}%)`;
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
    material.ambient.set(...lighting.materials.dungeon.ambient);
    material.update();

    return material;
}

function createStoneMaterial(texture) {
    const material = new pc.StandardMaterial();
    material.diffuseMap = texture;
    material.diffuseMapTiling.set(1, 1);
    material.diffuse.set(0.92, 0.92, 0.92);
    material.ambient.set(...lighting.materials.dungeon.ambient);
    material.update();

    return material;
}

function createDoorMaterial(texture) {
    const material = new pc.StandardMaterial();
    material.diffuseMap = texture;
    material.diffuseMapTiling.set(1, 1);
    material.diffuse.set(0.62, 0.49, 0.24);
    material.ambient.set(...lighting.materials.door.ambient);
    material.emissive.set(...lighting.materials.door.emissive);
    material.update();

    return material;
}

function createRecessMaterial(texture) {
    const material = new pc.StandardMaterial();
    material.diffuseMap = texture;
    material.diffuseMapTiling.set(1, 1);
    material.diffuse.set(0.16, 0.12, 0.08);
    material.ambient.set(...lighting.materials.recess.ambient);
    material.update();

    return material;
}

function createArchMaterial(texture) {
    const material = new pc.StandardMaterial();
    material.diffuseMap = texture;
    material.diffuseMapTiling.set(1, 1);
    material.diffuse.set(0.58, 0.54, 0.48);
    material.ambient.set(...lighting.materials.arch.ambient);
    material.update();

    return material;
}

function addBox(appInstance, name, position, scale, material, rotation = null) {
    const entity = new pc.Entity(name);
    entity.addComponent('render', {
        type: 'box',
        material,
        batchGroupId: staticBatchGroupId,
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
    metal.metalness = lighting.materials.torch_metal.metalness;
    metal.shininess = lighting.materials.torch_metal.shininess;
    metal.update();

    const wood = new pc.StandardMaterial();
    wood.diffuse.set(0.2, 0.09, 0.035);
    wood.ambient.set(...lighting.materials.torch_wood.ambient);
    wood.update();

    const flame = new pc.StandardMaterial();
    flame.diffuse.set(1, 0.3, 0.025);
    flame.emissive.set(...lighting.materials.torch_flame.emissive);
    flame.emissiveIntensity = lighting.materials.torch_flame.emissive_intensity;
    flame.update();

    return { metal, wood, flame };
}

function addTorch(appInstance, position, edge, materials) {
    const torch = new pc.Entity('wall-torch');
    torch.setLocalPosition(position.x, position.y, position.z);

    const bracket = new pc.Entity('torch-bracket');
    bracket.addComponent('render', { type: 'box', material: materials.metal, batchGroupId: staticBatchGroupId });
    bracket.setLocalScale(edge.dx === 0 ? 0.26 : 0.46, 0.16, edge.dy === 0 ? 0.26 : 0.46);
    torch.addChild(bracket);

    const handle = new pc.Entity('torch-handle');
    handle.addComponent('render', { type: 'cylinder', material: materials.wood, batchGroupId: staticBatchGroupId });
    handle.setLocalPosition(-edge.dx * 0.28, 0.28, -edge.dy * 0.28);
    handle.setLocalScale(0.14, 0.68, 0.14);
    handle.setLocalEulerAngles(edge.dy * -22, 0, edge.dx * 22);
    torch.addChild(handle);

    const flame = new pc.Entity('torch-flame');
    flame.addComponent('render', { type: 'sphere', material: materials.flame, batchGroupId: staticBatchGroupId });
    flame.setLocalPosition(-edge.dx * 0.52, 0.78, -edge.dy * 0.52);
    flame.setLocalScale(0.24, 0.42, 0.24);
    torch.addChild(flame);

    const light = new pc.Entity('torch-light');
    const torchLightConfig = lighting.torch.light;
    light.addComponent('light', {
        type: 'omni',
        color: colorFromRgb(torchLightConfig.color),
        intensity: torchLightConfig.intensity,
        // A torch should warm its own cell, not wash out the whole corridor.
        range: Math.max(tileSize * torchLightConfig.range_tiles * TORCH_LIGHT_RANGE_SCALE, 1),
        falloffMode: falloffModeFromName(torchLightConfig.falloff),
        castShadows: torchLightConfig.cast_shadows,
    });
    light.setLocalPosition(-edge.dx * 0.72, 0.75, -edge.dy * 0.72);
    torch.addChild(light);
    torchLights.push({
        light: light.light,
        baseIntensity: torchLightConfig.intensity,
        phase: Math.random() * Math.PI * 2,
    });
    appInstance.root.addChild(torch);
}

function updateLighting(dt = 0) {
    lightingTime += dt;
    const flickerConfig = lighting.torch.flicker;
    torchLights.forEach(({ light, baseIntensity, phase }) => {
        if (!flickerConfig.enabled) {
            light.intensity = baseIntensity;
            return;
        }

        const flicker = flickerConfig.base
            + Math.sin(lightingTime * flickerConfig.frequency_a + phase) * flickerConfig.amplitude_a
            + Math.sin(lightingTime * flickerConfig.frequency_b + phase * 1.7) * flickerConfig.amplitude_b;
        light.intensity = baseIntensity * flicker;
    });
}

function loadTexture(appInstance, url) {
    return new Promise((resolve, reject) => {
        const asset = new pc.Asset(`texture-${url}`, 'texture', { url });
        asset.once('load', () => {
            const texture = asset.resource;
            texture.addressU = pc.ADDRESS_CLAMP_TO_EDGE;
            texture.addressV = pc.ADDRESS_CLAMP_TO_EDGE;
            texture.minFilter = pc.FILTER_LINEAR_MIPMAP_LINEAR;
            texture.magFilter = pc.FILTER_LINEAR;
            resolve({ texture, width: texture.width, height: texture.height });
        });
        asset.once('error', () => reject(new Error(`Unable to load texture asset: ${url}`)));
        appInstance.assets.add(asset);
        appInstance.assets.load(asset);
    });
}

async function loadStoneMaterial(appInstance) {
    const image = await loadTexture(appInstance, wallTextureUrl);
    return createStoneMaterial(image.texture);
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

async function buildDungeon(appInstance, grid, material, stoneMaterial, decorations = []) {
    const floorThickness = 0.16;
    const wallThickness = 0.28;
    const torchMaterials = createTorchMaterials();
    const batcher = appInstance.batcher;
    const batchGroup = batcher?.getGroupByName('dungeon-static')
        || batcher?.addGroup('dungeon-static', false, 64);
    staticBatchGroupId = batchGroup?.id ?? -1;
    torchLights.length = 0;
    lightingTime = 0;
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
            stoneMaterial,
            rotation,
        );
        addBox(
            appInstance,
            'vertical-corridor-ceiling',
            { x: center.x, y: center.y + wallHeight + Math.cos(slopeRadians) * floorThickness / 2, z: center.z },
            corridorScale,
            stoneMaterial,
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
                    stoneMaterial,
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
                stoneMaterial,
                rotation,
            );
            addBox(
                appInstance,
                'ceiling',
                { x: pos.x, y: elevation + wallHeight + Math.cos(slopeRadians) * floorThickness / 2, z: pos.z },
                tileScale,
                stoneMaterial,
                rotation,
            );

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
                            stoneMaterial,
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

    // Static dungeon meshes never move. Spatial batches reduce thousands of box
    // submissions to a small number of draw calls while preserving culling.
    if (batcher && staticBatchGroupId >= 0) batcher.generate([staticBatchGroupId]);

    return;
}

defineExpose({
    dungeonWidth,
    dungeonHeight,
    tileSize,
    wallHeight,
    createDungeonTexture,
    loadStoneMaterial,
    createDoorTexture,
    createMaterial,
    createDoorMaterial,
    createRecessMaterial,
    createArchMaterial,
    buildDungeon,
    updateLighting,
    updateDecorations,
    worldPosition,
});
</script>

<template>
    <div hidden />
</template>
