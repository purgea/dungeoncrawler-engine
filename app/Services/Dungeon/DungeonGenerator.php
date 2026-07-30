<?php

declare(strict_types=1);

namespace App\Services\Dungeon;

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
     *     grid: array<int, array<int, array<string, mixed>|null>>,
     *     startRoom: array<string, int|bool>,
     *     spawn: array<string, int>
     *     decorations: list<array{asset: array<string, mixed>, floor: int, x: int, y: int}>
     * }
     */
    public function generate(array $decorationAssets = [], array $data = [], int $seed = 1): array
    {
        $this->random = new SeededRandom($seed);
        $width = max(15, (int) ($data['width'] ?? 63));
        $height = max(15, (int) ($data['height'] ?? 63));
        $tileSize = max(1, (int) ($data['tile_size'] ?? 4));
        $wallHeight = max(0.1, (float) ($data['wall_height'] ?? 3.3));
        $floorElevations = array_values($data['floors'] ?? [0, 10, -10]);
        $roomConfig = $data['rooms'] ?? [];
        $roomCount = max(1, (int) ($roomConfig['count_per_floor'] ?? 8));
        $minRoomWidth = max(3, (int) ($roomConfig['min_width'] ?? 4));
        $maxRoomWidth = max($minRoomWidth, (int) ($roomConfig['max_width'] ?? 8));
        $minRoomHeight = max(3, (int) ($roomConfig['min_height'] ?? 4));
        $maxRoomHeight = max($minRoomHeight, (int) ($roomConfig['max_height'] ?? 9));
        $placementAttempts = max(1, (int) ($roomConfig['placement_attempts'] ?? 180));
        $grid = array_fill(0, $height, array_fill(0, $width, null));
        $regionFloors = $this->shuffled($floorElevations);
        $regionWidth = (int) floor(($width - 6) / max(1, count($regionFloors)));
        $floorRegions = [
            ...array_map(fn (int $floor, int $index): array => [
                'floor' => $floor,
                'minX' => 2 + $index * ($regionWidth + 2),
                'maxX' => min($width - 3, 2 + ($index + 1) * $regionWidth),
            ], $regionFloors, array_keys($regionFloors)),
        ];
        $rooms = [];
        $verticalCorridors = [];

        for ($index = 1; $index < count($regionFloors); $index++) {
            $fromX = $floorRegions[$index - 1]['maxX'];
            $toX = $floorRegions[$index]['minX'];
            $connectorY = $this->random->int(3, $height - 4);
            $gatewayHeight = min(7, $height - 2);
            $gatewayY = max(1, min($connectorY - intdiv($gatewayHeight, 2), $height - $gatewayHeight - 1));

            $fromGateway = [
                'floor' => $regionFloors[$index - 1],
                'x' => max($floorRegions[$index - 1]['minX'], $fromX - 4),
                'y' => $gatewayY,
                'w' => min(5, $fromX - $floorRegions[$index - 1]['minX'] + 1),
                'h' => $gatewayHeight,
                'gateway' => true,
            ];
            $toGateway = [
                'floor' => $regionFloors[$index],
                'x' => $toX,
                'y' => $gatewayY,
                'w' => min(5, $floorRegions[$index]['maxX'] - $toX + 1),
                'h' => $gatewayHeight,
                'gateway' => true,
            ];

            $rooms[] = $fromGateway;
            $rooms[] = $toGateway;
            $verticalCorridors[] = [
                'from' => ['x' => $fromX, 'y' => $connectorY, 'floor' => $regionFloors[$index - 1]],
                'to' => ['x' => $toX, 'y' => $connectorY, 'floor' => $regionFloors[$index]],
            ];
        }

        foreach ($rooms as $room) {
            $this->carveRoom($grid, $room);
        }

        foreach ($floorRegions as $region) {
            for ($attempt = 0; $attempt < $placementAttempts && $this->roomCount($rooms, $region['floor']) < $roomCount; $attempt++) {
                $room = [
                    'floor' => $region['floor'],
                    'w' => $this->random->int($minRoomWidth, min($maxRoomWidth, $region['maxX'] - $region['minX'] - 1)),
                    'h' => $this->random->int($minRoomHeight, $maxRoomHeight),
                    'x' => $this->random->int($region['minX'], max($region['minX'], $region['maxX'] - $maxRoomWidth)),
                    'y' => $this->random->int(2, $height - $maxRoomHeight - 2),
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
            $this->carveVerticalCorridor($grid, $corridor['from'], $corridor['to'], $tileSize);
        }

        $startRooms = array_values(array_filter(
            $rooms,
            fn (array $room): bool => $room['floor'] === 0 && ! $room['gateway'],
        ));
        $startRoom = $startRooms !== []
            ? $this->random->pick($startRooms)
            : $this->firstRoomOnFloor($rooms, 0);
        $spawn = [
            'x' => (int) floor($startRoom['x'] + $startRoom['w'] / 2),
            'y' => (int) floor($startRoom['y'] + $startRoom['h'] / 2),
            'floor' => $startRoom['floor'],
        ];
        $decorationCount = (int) ($data['decorations']['count'] ?? 20);
        $floorAssets = array_values(array_filter($decorationAssets, fn (array $asset): bool => ($asset['placement'] ?? 'floor') === 'floor'));
        $decorations = $this->selectDecorations($grid, [$spawn], $floorAssets, $width, $height, $decorationCount);

        return [
            'schemaVersion' => 1,
            'width' => $width,
            'height' => $height,
            'tileSize' => $tileSize,
            'wallHeight' => $wallHeight,
            'floors' => $floorElevations,
            'grid' => $grid,
            'startRoom' => $startRoom,
            'spawn' => $spawn,
            'decorations' => $decorations,
        ];
    }

    /** @return array<string, mixed> */
    private function createCell(int $floor, string $type = 'floor', ?float $elevation = null): array
    {
        return [
            'walkable' => true,
            'type' => $type,
            'floor' => $floor,
            'elevation' => $elevation ?? $floor,
            'slope' => 0,
            'direction' => ['x' => 0, 'y' => 0],
        ];
    }

    /** @param array<int, array<int, array<string, mixed>|null>> $grid */
    private function carveRoom(array &$grid, array $room): void
    {
        for ($y = $room['y']; $y < $room['y'] + $room['h']; $y++) {
            for ($x = $room['x']; $x < $room['x'] + $room['w']; $x++) {
                $grid[$y][$x] = $this->createCell($room['floor']);
            }
        }
    }

    /** @param array<int, array<int, array<string, mixed>|null>> $grid */
    private function carveCorridor(array &$grid, array $from, array $to): void
    {
        $carveX = function (int $x1, int $x2, int $y) use (&$grid, $from): void {
            for ($x = min($x1, $x2); $x <= max($x1, $x2); $x++) {
                $grid[$y][$x] = $this->createCell($from['floor']);
            }
        };
        $carveY = function (int $y1, int $y2, int $x) use (&$grid, $from): void {
            for ($y = min($y1, $y2); $y <= max($y1, $y2); $y++) {
                $grid[$y][$x] = $this->createCell($from['floor']);
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
    private function carveVerticalCorridor(array &$grid, array $from, array $to, int $tileSize): void
    {
        $distance = abs($to['x'] - $from['x']) + abs($to['y'] - $from['y']);
        $tileCount = $distance + 1;
        $elevationDelta = $to['floor'] - $from['floor'];
        $slope = rad2deg(atan2($elevationDelta, $tileCount * $tileSize));

        if (abs($slope) > 60) {
            throw new RuntimeException(sprintf('Vertical corridor slope %.1f exceeds 60 degrees.', abs($slope)));
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

    private function firstRoomOnFloor(array $rooms, int $floor): array
    {
        foreach ($rooms as $room) {
            if ($room['floor'] === $floor) {
                return $room;
            }
        }

        throw new RuntimeException("No room was generated on floor {$floor}.");
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
                    if (($point['floor'] ?? null) === $cell['floor'] && $point['x'] === $x && $point['y'] === $y) {
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
            'asset' => $assets === [] ? [] : $this->random->pick($assets),
        ], array_slice($candidates, 0, $count));
    }

    private function isOpenRoomTile(array $grid, int $x, int $y, int $floor): bool
    {
        for ($offsetY = -1; $offsetY <= 1; $offsetY++) {
            for ($offsetX = -1; $offsetX <= 1; $offsetX++) {
                if ($offsetX === 0 && $offsetY === 0) {
                    continue;
                }
                $neighbor = $grid[$y + $offsetY][$x + $offsetX] ?? null;
                if (! $neighbor || $neighbor['type'] !== 'floor' || $neighbor['floor'] !== $floor) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @template T @param list<T> $values @return list<T> */
    private function shuffled(array $values): array
    {
        return $this->random->shuffle($values);
    }
}
