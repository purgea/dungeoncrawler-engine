<?php

namespace Tests\Unit;

use App\Services\Dungeon\DungeonGenerator;
use App\Services\Dungeon\SeededRandom;
use PHPUnit\Framework\TestCase;

final class DungeonGeneratorTest extends TestCase
{
    public function test_it_generates_a_complete_renderable_layout(): void
    {
        $generator = new DungeonGenerator;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $layout = $generator->generate();

            $this->assertSame(1, $layout['schemaVersion']);
            $this->assertSame(63, $layout['width']);
            $this->assertSame(63, $layout['height']);
            $this->assertCount(63, $layout['grid']);
            $this->assertSame(0, $layout['spawn']['floor']);
            $this->assertTrue($layout['grid'][$layout['spawn']['y']][$layout['spawn']['x']]['walkable']);

            $verticalCells = [];
            foreach ($layout['grid'] as $row) {
                foreach ($row as $cell) {
                    if (($cell['type'] ?? null) === 'vertical-corridor') {
                        $verticalCells[] = $cell;
                    }
                }
            }

            $this->assertNotEmpty($verticalCells);
            foreach ($verticalCells as $cell) {
                $this->assertLessThanOrEqual(60, abs($cell['slope']));
            }
            $this->assertVerticalCorridorsConnectToFloors($layout['grid']);

            $this->assertCount(20, $layout['decorations']);
            $this->assertCount(20, array_unique(array_map(
                fn (array $decoration): string => $decoration['floor'] . ':' . $decoration['x'] . ':' . $decoration['y'],
                $layout['decorations'],
            )));
        }
    }

    public function test_it_uses_level_generation_data(): void
    {
        $layout = (new DungeonGenerator)->generate([], [
            'width' => 31,
            'height' => 31,
            'tile_size' => 3,
            'wall_height' => 4.5,
            'floors' => [0, 8],
            'rooms' => [
                'count_per_floor' => 3,
                'min_width' => 3,
                'max_width' => 5,
                'min_height' => 3,
                'max_height' => 5,
                'placement_attempts' => 100,
            ],
            'decorations' => ['count' => 0],
        ]);

        $this->assertSame(31, $layout['width']);
        $this->assertSame(31, $layout['height']);
        $this->assertSame(3, $layout['tileSize']);
        $this->assertSame(4.5, $layout['wallHeight']);
        $this->assertSame([0, 8], $layout['floors']);
        $this->assertCount(31, $layout['grid']);
    }

    public function test_same_seed_produces_the_same_layout(): void
    {
        $generator = new DungeonGenerator;
        $first = $generator->generate([], [], 123456);
        $second = $generator->generate([], [], 123456);

        $this->assertSame($first, $second);
    }

    public function test_different_seeds_produce_different_layouts(): void
    {
        $generator = new DungeonGenerator;
        $first = $generator->generate([], [], 123456);
        $second = $generator->generate([], [], 654321);

        $this->assertNotSame($first['grid'], $second['grid']);
    }

    public function test_maximum_persistable_seed_survives_a_json_round_trip(): void
    {
        $storedSeed = json_decode(json_encode(SeededRandom::MAX_SEED, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(SeededRandom::MAX_SEED, $storedSeed);

        $generator = new DungeonGenerator;
        $this->assertSame(
            $generator->generate([], [], SeededRandom::MAX_SEED),
            $generator->generate([], [], $storedSeed),
        );
    }

    private function assertVerticalCorridorsConnectToFloors(array $grid): void
    {
        foreach ($grid as $y => $row) {
            foreach ($row as $x => $cell) {
                if (($cell['type'] ?? null) !== 'vertical-corridor') {
                    continue;
                }

                $direction = $cell['direction'];
                $previous = $grid[$y - $direction['y']][$x - $direction['x']] ?? null;
                if (($previous['type'] ?? null) === 'vertical-corridor') {
                    continue;
                }

                $cursorX = $x;
                $cursorY = $y;
                while (($grid[$cursorY][$cursorX]['type'] ?? null) === 'vertical-corridor') {
                    $last = $grid[$cursorY][$cursorX];
                    $cursorX += $direction['x'];
                    $cursorY += $direction['y'];
                }

                $this->assertSame('floor', $previous['type'] ?? null, 'Ramp is missing its entrance floor.');
                $this->assertSame($cell['floor'], $previous['floor'] ?? null);
                $this->assertSame('floor', $grid[$cursorY][$cursorX]['type'] ?? null, 'Ramp is missing its exit floor.');
                $this->assertSame($last['floor'], $grid[$cursorY][$cursorX]['floor'] ?? null);
            }
        }
    }
}
