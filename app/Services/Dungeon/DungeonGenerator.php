<?php

namespace App\Services\Dungeon;

use InvalidArgumentException;
use RuntimeException;

final class DungeonGenerator
{
    private SeededRandom $random;

    /**
     * @return array{
     *     schemaVersion: int,
     *     width: int,
     *     height: int,
     *     tileSize: int,
     *     wallHeight: float,
     *     floors: list<int>,
     *     lighting: array<string, mixed>,
     *     grid: array<int, array<int, array<string, mixed>|null>>,
     *     startRoom: array<string, int|bool>,
     *     spawn: array<string, int>,
     *     decorations: list<array{asset: array<string, mixed>, floor: int, x: int, y: int}>,
     *     seed: int,
     *     enemies: list<array<string, mixed>>,
     *     pickups: list<array<string, mixed>>,
     *     traps: list<array<string, mixed>>,
     *     exit: array{x: int, y: int, floor: int},
     *     requiredSigils: int,
     *     definitions: array<string, list<array<string, mixed>>>
     * }
     */
    public function generate(array $data, int $seed, array $lighting, array $definitions): array
    {
        if ($lighting === []) {
            throw new InvalidArgumentException('Dungeon generation requires stage lighting from the database.');
        }
        foreach (['rule', 'weapon', 'enemy', 'pickup', 'trap', 'decoration'] as $kind) {
            if (! array_key_exists($kind, $definitions)) {
                throw new InvalidArgumentException("Dungeon generation requires the {$kind} definitions from the database.");
            }
        }
        $this->random = new SeededRandom($seed);
        $width = (int) $data['width'];
        $height = (int) $data['height'];
        $tileSize = (int) $data['tile_size'];
        $wallHeight = (float) $data['wall_height'];
        $roomConfig = $data['rooms'];
        $roomCount = (int) $roomConfig['count_per_floor'];
        $minRoomWidth = (int) $roomConfig['min_width'];
        $maxRoomWidth = (int) $roomConfig['max_width'];
        $minRoomHeight = (int) $roomConfig['min_height'];
        $maxRoomHeight = (int) $roomConfig['max_height'];
        $placementAttempts = (int) $roomConfig['placement_attempts'];
        $floorCount = (int) $data['floor_count'];
        $layoutConfig = $data['layout'];
        $regionMargin = (int) $layoutConfig['region_margin'];
        $roomMargin = (int) $layoutConfig['room_margin'];
        $gatewayMargin = (int) $layoutConfig['gateway_margin'];
        $regionGap = (int) $layoutConfig['region_gap'];
        $connectorYMin = (int) $layoutConfig['connector_y_min'];
        $connectorYMax = (int) $layoutConfig['connector_y_max'];
        $rampCountMin = (int) $layoutConfig['ramp_count_min'];
        $rampCountMax = (int) $layoutConfig['ramp_count_max'];
        $gatewayHeightMin = (int) $layoutConfig['gateway_height_min'];
        $gatewayHeightMax = (int) $layoutConfig['gateway_height_max'];
        $gatewayWidthMin = (int) $layoutConfig['gateway_width_min'];
        $gatewayWidthMax = (int) $layoutConfig['gateway_width_max'];
        $floorElevationStep = (int) $layoutConfig['floor_elevation_step'];
        $startFloor = (int) $layoutConfig['start_floor'];
        $maxSlope = (float) $layoutConfig['max_slope'];
        $decorationCount = (int) $data['decorations']['count'];
        $this->validateConfiguration(
            $width,
            $height,
            $tileSize,
            $wallHeight,
            $roomCount,
            $minRoomWidth,
            $maxRoomWidth,
            $minRoomHeight,
            $maxRoomHeight,
            $placementAttempts,
            $floorCount,
            $regionMargin,
            $roomMargin,
            $gatewayMargin,
            $regionGap,
            $connectorYMin,
            $connectorYMax,
            $rampCountMin,
            $rampCountMax,
            $gatewayHeightMin,
            $gatewayHeightMax,
            $gatewayWidthMin,
            $gatewayWidthMax,
            $floorElevationStep,
            $startFloor,
            $maxSlope,
            $decorationCount,
        );
        $floorElevations = $this->generateFloorElevations($floorCount, $floorElevationStep);
        if (! in_array($startFloor, $floorElevations, true)) {
            throw new InvalidArgumentException('The configured start floor is not present in the generated floor elevations.');
        }
        $grid = array_fill(0, $height, array_fill(0, $width, null));
        $regionFloors = $floorElevations;
        sort($regionFloors, SORT_NUMERIC);
        if ($this->random->int(0, 1) === 1) {
            $regionFloors = array_reverse($regionFloors);
        }
        $availableRegionWidth = $width - $regionMargin * 2 - $regionGap * (count($regionFloors) - 1);
        if ($availableRegionWidth < $floorCount * $minRoomWidth) {
            throw new InvalidArgumentException('Dungeon layout does not have enough width for the configured floors and rooms.');
        }
        $baseRegionWidth = intdiv($availableRegionWidth, count($regionFloors));
        $extraRegionWidth = $availableRegionWidth % count($regionFloors);
        $floorRegions = [];
        $minX = $regionMargin;
        foreach ($regionFloors as $index => $floor) {
            $regionWidth = $baseRegionWidth + ($index < $extraRegionWidth ? 1 : 0);
            $floorRegions[] = [
                'floor' => $floor,
                'minX' => $minX,
                'maxX' => $minX + $regionWidth - 1,
            ];
            $minX += $regionWidth + $regionGap;
        }
        foreach ($floorRegions as $region) {
            $regionWidth = $region['maxX'] - $region['minX'] + 1;
            if ($regionWidth < $maxRoomWidth || $regionWidth < $gatewayWidthMax) {
                throw new InvalidArgumentException('Dungeon room configuration does not fit within a generated floor region.');
            }
        }
        $rooms = [];
        $verticalCorridors = [];

        for ($index = 1; $index < count($regionFloors); $index++) {
            $fromX = $floorRegions[$index - 1]['maxX'];
            $toX = $floorRegions[$index]['minX'];
            $rampRows = $this->random->shuffle(range($connectorYMin, $connectorYMax));
            $rampCount = $this->random->int($rampCountMin, $rampCountMax);

            for ($rampIndex = 0; $rampIndex < $rampCount; $rampIndex++) {
                $connectorY = $rampRows[$rampIndex];
                $gatewayHeight = $this->random->int($gatewayHeightMin, $gatewayHeightMax);
                $gatewayY = max($gatewayMargin, min($connectorY - intdiv($gatewayHeight, 2), $height - $gatewayHeight - $gatewayMargin));
                $fromGatewayWidth = $this->random->int($gatewayWidthMin, $gatewayWidthMax);
                $toGatewayWidth = $this->random->int($gatewayWidthMin, $gatewayWidthMax);

                $rooms[] = [
                    'floor' => $regionFloors[$index - 1],
                    'x' => $fromX - $fromGatewayWidth + 1,
                    'y' => $gatewayY,
                    'w' => $fromGatewayWidth,
                    'h' => $gatewayHeight,
                    'gateway' => true,
                ];
                $rooms[] = [
                    'floor' => $regionFloors[$index],
                    'x' => $toX,
                    'y' => $gatewayY,
                    'w' => $toGatewayWidth,
                    'h' => $gatewayHeight,
                    'gateway' => true,
                ];
                $verticalCorridors[] = [
                    'from' => ['x' => $fromX, 'y' => $connectorY, 'floor' => $regionFloors[$index - 1]],
                    'to' => ['x' => $toX, 'y' => $connectorY, 'floor' => $regionFloors[$index]],
                ];
            }
        }

        foreach ($rooms as $room) {
            $this->carveRoom($grid, $room);
        }

        foreach ($floorRegions as $region) {
            for ($attempt = 0; $attempt < $placementAttempts && $this->roomCount($rooms, $region['floor']) < $roomCount; $attempt++) {
                $roomWidth = $this->random->int($minRoomWidth, min($maxRoomWidth, $region['maxX'] - $region['minX'] + 1));
                $roomHeight = $this->random->int($minRoomHeight, $maxRoomHeight);
                $room = [
                    'floor' => $region['floor'],
                    'w' => $roomWidth,
                    'h' => $roomHeight,
                    'x' => $this->random->int($region['minX'], $region['maxX'] - $roomWidth + 1),
                    'y' => $this->random->int($roomMargin, $height - $roomHeight - $roomMargin),
                    'gateway' => false,
                ];

                if ($this->intersectsAny($room, $rooms)) {
                    continue;
                }

                $this->carveRoom($grid, $room);
                $rooms[] = $room;
            }
        }

        foreach ($floorElevations as $floor) {
            $centers = [];
            foreach ($rooms as $room) {
                if ($room['floor'] !== $floor) {
                    continue;
                }

                $centers[] = [
                    'floor' => $floor,
                    'x' => (int) floor($room['x'] + $room['w'] / 2),
                    'y' => (int) floor($room['y'] + $room['h'] / 2),
                ];
            }

            for ($index = 1; $index < count($centers); $index++) {
                $this->carveCorridor($grid, $centers[$index - 1], $centers[$index]);
            }
        }

        foreach ($verticalCorridors as $corridor) {
            $this->carveVerticalCorridor($grid, $corridor['from'], $corridor['to'], $tileSize, $maxSlope);
        }

        $startRooms = array_values(array_filter(
            $rooms,
            fn (array $room): bool => $room['floor'] === $startFloor && ! $room['gateway'],
        ));
        if ($startRooms === []) {
            throw new RuntimeException("No non-gateway room was generated on the configured start floor {$startFloor}.");
        }
        $startRoom = $this->random->pick($startRooms);
        $spawn = [
            'x' => (int) floor($startRoom['x'] + $startRoom['w'] / 2),
            'y' => (int) floor($startRoom['y'] + $startRoom['h'] / 2),
            'floor' => $startRoom['floor'],
        ];
        $decorationDefinitions = $definitions['decoration'];
        $population = (new DungeonPopulation)->populate([
            'grid' => $grid, 'tileSize' => $tileSize, 'spawn' => $spawn,
        ], $seed, $definitions, $data['population']);
        $floorAssets = array_values(array_filter($decorationDefinitions, fn (array $definition): bool => $definition['placement'] === 'floor'));
        if ($decorationCount > 0 && $floorAssets === []) {
            throw new InvalidArgumentException('Dungeon decorations require at least one floor decoration definition.');
        }
        $decorations = $this->selectDecorations($grid, [
            $spawn, $population['exit'], ...$population['enemies'], ...$population['pickups'], ...$population['traps'],
        ], $floorAssets, $width, $height, $decorationCount);

        return [
            'schemaVersion' => 1,
            'width' => $width,
            'height' => $height,
            'tileSize' => $tileSize,
            'wallHeight' => $wallHeight,
            'floors' => $floorElevations,
            'lighting' => $lighting,
            'definitions' => $definitions,
            'grid' => $grid,
            'startRoom' => $startRoom,
            'spawn' => $spawn,
            'decorations' => $decorations,
            ...$population,
        ];
    }

    /** @return list<int> */
    private function generateFloorElevations(int $floorCount, int $elevationStep): array
    {
        $lowestStart = -($floorCount - 1);
        $start = $this->random->int($lowestStart, 0);

        return array_map(
            fn (int $offset): int => ($start + $offset) * $elevationStep,
            range(0, $floorCount - 1),
        );
    }

    /** @return array<string, mixed> */
    private function createCell(int $floor, string $type, float $elevation): array
    {
        return [
            'walkable' => true,
            'type' => $type,
            'floor' => $floor,
            'elevation' => $elevation,
            'slope' => 0,
            'direction' => ['x' => 0, 'y' => 0],
        ];
    }

    /** @param array<int, array<int, array<string, mixed>|null>> $grid */
    private function carveRoom(array &$grid, array $room): void
    {
        for ($y = $room['y']; $y < $room['y'] + $room['h']; $y++) {
            for ($x = $room['x']; $x < $room['x'] + $room['w']; $x++) {
                $grid[$y][$x] = $this->createCell($room['floor'], 'floor', $room['floor']);
            }
        }
    }

    /** @param array<int, array<int, array<string, mixed>|null>> $grid */
    private function carveCorridor(array &$grid, array $from, array $to): void
    {
        $carveX = function (int $x1, int $x2, int $y) use (&$grid, $from): void {
            for ($x = min($x1, $x2); $x <= max($x1, $x2); $x++) {
                $grid[$y][$x] = $this->createCell($from['floor'], 'floor', $from['floor']);
            }
        };
        $carveY = function (int $y1, int $y2, int $x) use (&$grid, $from): void {
            for ($y = min($y1, $y2); $y <= max($y1, $y2); $y++) {
                $grid[$y][$x] = $this->createCell($from['floor'], 'floor', $from['floor']);
            }
        };

        if ($this->random->int(0, 1) === 1) {
            $carveX($from['x'], $to['x'], $from['y']);
            $carveY($from['y'], $to['y'], $to['x']);
        } else {
            $carveY($from['y'], $to['y'], $from['x']);
            $carveX($from['x'], $to['x'], $to['y']);
        }
    }

    /** @param array<int, array<int, array<string, mixed>|null>> $grid */
    private function carveVerticalCorridor(array &$grid, array $from, array $to, int $tileSize, float $maxSlope): void
    {
        $distance = abs($to['x'] - $from['x']) + abs($to['y'] - $from['y']);
        $tileCount = $distance + 1;
        $elevationDelta = $to['floor'] - $from['floor'];
        $slope = rad2deg(atan2($elevationDelta, $tileCount * $tileSize));

        if (abs($slope) > $maxSlope) {
            throw new RuntimeException(sprintf('Vertical corridor slope %.1f exceeds %.1f degrees.', abs($slope), $maxSlope));
        }

        $stepX = $to['x'] <=> $from['x'];
        $stepY = $to['y'] <=> $from['y'];
        for ($step = 0; $step <= $distance; $step++) {
            $progress = ($step + 0.5) / $tileCount;
            $x = $from['x'] + $stepX * min($step, abs($to['x'] - $from['x']));
            $y = $from['y'] + $stepY * max(0, $step - abs($to['x'] - $from['x']));
            $cell = $this->createCell(
                $progress < 0.5 ? $from['floor'] : $to['floor'],
                'vertical-corridor',
                $from['floor'] + $elevationDelta * $progress,
            );
            $cell['slope'] = $slope;
            $cell['direction'] = ['x' => $stepX, 'y' => $stepY];
            $grid[$y][$x] = $cell;
        }
    }

    private function intersectsAny(array $room, array $rooms): bool
    {
        foreach ($rooms as $existing) {
            if ($room['floor'] !== $existing['floor']) {
                continue;
            }

            if (! (
                $existing['x'] > $room['x'] + $room['w'] + 1 ||
                $room['x'] > $existing['x'] + $existing['w'] + 1 ||
                $existing['y'] > $room['y'] + $room['h'] + 1 ||
                $room['y'] > $existing['y'] + $existing['h'] + 1
            )) {
                return true;
            }
        }

        return false;
    }

    private function roomCount(array $rooms, int $floor): int
    {
        return count(array_filter($rooms, fn (array $room): bool => $room['floor'] === $floor));
    }

    /** @return list<array{floor: int, x: int, y: int}> */
    private function selectDecorations(array $grid, array $excluded, array $assets, int $width, int $height, int $count): array
    {
        $candidates = [];
        for ($y = 1; $y < $height - 1; $y++) {
            for ($x = 1; $x < $width - 1; $x++) {
                $cell = $grid[$y][$x];
                if (! $cell || $cell['type'] !== 'floor' || ! $this->isOpenRoomTile($grid, $x, $y, $cell['floor'])) {
                    continue;
                }
                $blocked = false;
                foreach ($excluded as $point) {
                    if ($point['floor'] === $cell['floor'] && $point['x'] === $x && $point['y'] === $y) {
                        $blocked = true;
                        break;
                    }
                }
                if (! $blocked) {
                    $candidates[] = ['floor' => $cell['floor'], 'x' => $x, 'y' => $y];
                }
            }
        }
        $candidates = $this->random->shuffle($candidates);

        return array_map(fn (array $candidate): array => [
            ...$candidate,
            'asset' => $this->random->pick($assets),
        ], array_slice($candidates, 0, $count));
    }

    private function isOpenRoomTile(array $grid, int $x, int $y, int $floor): bool
    {
        for ($offsetY = -1; $offsetY <= 1; $offsetY++) {
            for ($offsetX = -1; $offsetX <= 1; $offsetX++) {
                if ($offsetX === 0 && $offsetY === 0) {
                    continue;
                }
                $neighbor = $grid[$y + $offsetY][$x + $offsetX];
                if (! $neighbor || $neighbor['type'] !== 'floor' || $neighbor['floor'] !== $floor) {
                    return false;
                }
            }
        }

        return true;
    }

    private function validateConfiguration(
        int $width,
        int $height,
        int $tileSize,
        float $wallHeight,
        int $roomCount,
        int $minRoomWidth,
        int $maxRoomWidth,
        int $minRoomHeight,
        int $maxRoomHeight,
        int $placementAttempts,
        int $floorCount,
        int $regionMargin,
        int $roomMargin,
        int $gatewayMargin,
        int $regionGap,
        int $connectorYMin,
        int $connectorYMax,
        int $rampCountMin,
        int $rampCountMax,
        int $gatewayHeightMin,
        int $gatewayHeightMax,
        int $gatewayWidthMin,
        int $gatewayWidthMax,
        int $floorElevationStep,
        int $startFloor,
        float $maxSlope,
        int $decorationCount,
    ): void {
        if ($width <= 0 || $height <= 0 || $tileSize <= 0 || $wallHeight <= 0) {
            throw new InvalidArgumentException('Dungeon dimensions, tile size, and wall height must be positive.');
        }
        if ($roomCount <= 0 || $minRoomWidth <= 0 || $maxRoomWidth < $minRoomWidth || $minRoomHeight <= 0 || $maxRoomHeight < $minRoomHeight || $placementAttempts <= 0) {
            throw new InvalidArgumentException('Dungeon room configuration is invalid.');
        }
        if ($floorCount <= 0 || $regionMargin <= 0 || $roomMargin <= 0 || $gatewayMargin <= 0 || $regionGap < 0) {
            throw new InvalidArgumentException('Dungeon floor and margin configuration is invalid.');
        }
        if ($connectorYMin > $connectorYMax || $connectorYMin < 1 || $connectorYMax >= $height - 1 || $rampCountMin <= 0 || $rampCountMax < $rampCountMin || $rampCountMax > $connectorYMax - $connectorYMin + 1) {
            throw new InvalidArgumentException('Dungeon connector configuration is invalid.');
        }
        if ($gatewayHeightMin <= 0 || $gatewayHeightMax < $gatewayHeightMin || $gatewayHeightMax + $gatewayMargin * 2 > $height || $gatewayWidthMin <= 0 || $gatewayWidthMax < $gatewayWidthMin || $minRoomHeight + $roomMargin * 2 > $height || $maxRoomHeight + $roomMargin * 2 > $height) {
            throw new InvalidArgumentException('Dungeon gateway configuration is invalid.');
        }
        if ($floorElevationStep <= 0 || $startFloor < 0 || $maxSlope <= 0 || $decorationCount < 0) {
            throw new InvalidArgumentException('Dungeon elevation and decoration configuration is invalid.');
        }
    }
}
