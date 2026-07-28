<?php

declare(strict_types=1);

namespace App\Services\Dungeon;

use RuntimeException;

final class DungeonGenerator
{
    public const WIDTH = 63;

    public const HEIGHT = 63;

    public const TILE_SIZE = 4;

    public const WALL_HEIGHT = 3.3;

    /** @var list<int> */
    private const FLOOR_ELEVATIONS = [0, 10, -10];

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
     *     door: array<string, int>,
     *     spawn: array<string, int>
     *     decorations: list<array{asset: array<string, mixed>, floor: int, x: int, y: int}>
     * }
     */
    public function generate(array $decorationAssets = []): array
    {
        $grid = array_fill(0, self::HEIGHT, array_fill(0, self::WIDTH, null));
        $firstConnectorY = random_int(8, 25);
        $secondConnectorY = random_int(35, 53);
        $regionFloors = $this->shuffled(self::FLOOR_ELEVATIONS);
        $floorRegions = [
            ['floor' => $regionFloors[0], 'minX' => 2, 'maxX' => 20],
            ['floor' => $regionFloors[1], 'minX' => 24, 'maxX' => 40],
            ['floor' => $regionFloors[2], 'minX' => 44, 'maxX' => self::WIDTH - 3],
        ];
        $rooms = [
            ['floor' => $regionFloors[0], 'x' => 16, 'y' => $firstConnectorY - 3, 'w' => 5, 'h' => 7, 'gateway' => true],
            ['floor' => $regionFloors[1], 'x' => 24, 'y' => $firstConnectorY - 3, 'w' => 5, 'h' => 7, 'gateway' => true],
            ['floor' => $regionFloors[1], 'x' => 36, 'y' => $secondConnectorY - 3, 'w' => 5, 'h' => 7, 'gateway' => true],
            ['floor' => $regionFloors[2], 'x' => 44, 'y' => $secondConnectorY - 3, 'w' => 5, 'h' => 7, 'gateway' => true],
        ];

        foreach ($rooms as $room) {
            $this->carveRoom($grid, $room);
        }

        foreach ($floorRegions as $region) {
            for ($attempt = 0; $attempt < 180 && $this->roomCount($rooms, $region['floor']) < 8; $attempt++) {
                $room = [
                    'floor' => $region['floor'],
                    'w' => random_int(4, min(8, $region['maxX'] - $region['minX'] - 1)),
                    'h' => random_int(4, 9),
                    'x' => random_int($region['minX'], $region['maxX'] - 7),
                    'y' => random_int(2, self::HEIGHT - 11),
                    'gateway' => false,
                ];

                if ($this->intersectsAny($room, $rooms)) {
                    continue;
                }

                $this->carveRoom($grid, $room);
                $rooms[] = $room;
            }
        }

        foreach (self::FLOOR_ELEVATIONS as $floor) {
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

        $this->carveVerticalCorridor(
            $grid,
            ['x' => 20, 'y' => $firstConnectorY, 'floor' => $regionFloors[0]],
            ['x' => 24, 'y' => $firstConnectorY, 'floor' => $regionFloors[1]],
        );
        $this->carveVerticalCorridor(
            $grid,
            ['x' => 40, 'y' => $secondConnectorY, 'floor' => $regionFloors[1]],
            ['x' => 44, 'y' => $secondConnectorY, 'floor' => $regionFloors[2]],
        );

        $startRooms = array_values(array_filter(
            $rooms,
            fn (array $room): bool => $room['floor'] === 0 && ! $room['gateway'],
        ));
        $startRoom = $startRooms !== []
            ? $startRooms[array_rand($startRooms)]
            : $this->firstRoomOnFloor($rooms, 0);
        $spawn = [
            'x' => (int) floor($startRoom['x'] + $startRoom['w'] / 2),
            'y' => (int) floor($startRoom['y'] + $startRoom['h'] / 2),
            'floor' => $startRoom['floor'],
        ];
        $door = $this->selectDoor($startRoom, $spawn);
        $relic = $this->selectRelic($grid, [[
            'floor' => $startRoom['floor'],
            'x' => $startRoom['x'] + (int) floor($startRoom['w'] / 2),
            'y' => $startRoom['y'] + (int) floor($startRoom['h'] / 2),
        ]]);
        $decorations = $this->selectDecorations($grid, [$spawn, $door, $relic], $decorationAssets);
        foreach ($decorations as $decoration) {
        }

        return [
            'schemaVersion' => 1,
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'tileSize' => self::TILE_SIZE,
            'wallHeight' => self::WALL_HEIGHT,
            'floors' => self::FLOOR_ELEVATIONS,
            'grid' => $grid,
            'startRoom' => $startRoom,
            'door' => [...$door, 'floor' => $startRoom['floor']],
            'spawn' => $spawn,
            'relic' => $relic,
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

        if (random_int(0, 1) === 1) {
            $carveX($from['x'], $to['x'], $from['y']);
            $carveY($from['y'], $to['y'], $to['x']);
        } else {
            $carveY($from['y'], $to['y'], $from['x']);
            $carveX($from['x'], $to['x'], $to['y']);
        }
    }

    /** @param array<int, array<int, array<string, mixed>|null>> $grid */
    private function carveVerticalCorridor(array &$grid, array $from, array $to): void
    {
        $distance = abs($to['x'] - $from['x']) + abs($to['y'] - $from['y']);
        $tileCount = $distance + 1;
        $elevationDelta = $to['floor'] - $from['floor'];
        $slope = rad2deg(atan2($elevationDelta, $tileCount * self::TILE_SIZE));

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

    /** @return array{x: int, y: int, dx: int, dy: int} */
    private function selectDoor(array $room, array $spawn): array
    {
        $candidates = [];
        for ($x = $room['x']; $x < $room['x'] + $room['w']; $x++) {
            $candidates[] = ['x' => $x, 'y' => $room['y'], 'dx' => 0, 'dy' => -1];
            $candidates[] = ['x' => $x, 'y' => $room['y'] + $room['h'] - 1, 'dx' => 0, 'dy' => 1];
        }
        for ($y = $room['y'] + 1; $y < $room['y'] + $room['h'] - 1; $y++) {
            $candidates[] = ['x' => $room['x'], 'y' => $y, 'dx' => -1, 'dy' => 0];
            $candidates[] = ['x' => $room['x'] + $room['w'] - 1, 'y' => $y, 'dx' => 1, 'dy' => 0];
        }

        usort($candidates, fn (array $a, array $b): int => hypot($a['x'] - $spawn['x'], $a['y'] - $spawn['y'])
            <=> hypot($b['x'] - $spawn['x'], $b['y'] - $spawn['y'])
        );

        return $candidates[0];
    }

    /** @return array{floor: int, x: int, y: int} */
    private function selectRelic(array $grid, array $excluded): array
    {
        $candidates = [];
        for ($y = 1; $y < self::HEIGHT - 1; $y++) {
            for ($x = 1; $x < self::WIDTH - 1; $x++) {
                $cell = $grid[$y][$x];
                if (! $cell || $cell['type'] === 'vertical-corridor') {
                    continue;
                }

                $isExcluded = false;
                foreach ($excluded as $point) {
                    if ($point['floor'] === $cell['floor'] && $point['x'] === $x && $point['y'] === $y) {
                        $isExcluded = true;
                        break;
                    }
                }
                if (! $isExcluded) {
                    $candidates[] = ['floor' => $cell['floor'], 'x' => $x, 'y' => $y];
                }
            }
        }

        if ($candidates === []) {
            throw new RuntimeException('No valid relic tile was generated.');
        }

        return $candidates[array_rand($candidates)];
    }

    /** @return list<array{floor: int, x: int, y: int}> */
    private function selectDecorations(array $grid, array $excluded, array $assets): array
    {
        $candidates = [];
        for ($y = 1; $y < self::HEIGHT - 1; $y++) {
            for ($x = 1; $x < self::WIDTH - 1; $x++) {
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
        shuffle($candidates);

        return array_map(fn (array $candidate): array => [
            ...$candidate,
            'asset' => $assets === [] ? [] : $assets[array_rand($assets)],
        ], array_slice($candidates, 0, 20));
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
        shuffle($values);

        return $values;
    }
}
