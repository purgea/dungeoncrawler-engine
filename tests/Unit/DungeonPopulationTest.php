<?php

namespace Tests\Unit;

use App\Services\Dungeon\DungeonGenerator;
use App\Services\Dungeon\DungeonTraversal;
use PHPUnit\Framework\TestCase;

class DungeonPopulationTest extends TestCase
{
    public function test_objectives_and_combat_are_reachable_unique_and_clear_of_spawn(): void
    {
        foreach ([1, 2, 3, 42, 987654, 2147483647] as $seed) {
            $layout = (new DungeonGenerator)->generate([], [], $seed);
            $traversal = new DungeonTraversal;
            $distances = $traversal->distances($layout['grid'], $layout['tileSize'], $layout['spawn']);
            $this->assertCount(array_sum(array_map(fn (array $row): int => count(array_filter($row)), $layout['grid'])), $distances, 'Every walkable tile must be connected through usable ramps.');
            $this->assertSame($seed, $layout['seed']);
            $this->assertSame(3, $layout['requiredSigils']);
            $this->assertGreaterThanOrEqual(15, count($layout['enemies']));
            $this->assertLessThanOrEqual(25, count($layout['enemies']));
            $this->assertSame(['acolyte', 'imp', 'warden'], $this->types($layout['enemies']));
            $this->assertSame(['armor', 'health', 'mana', 'sigil', 'weapon'], $this->types($layout['pickups']));
            $this->assertSame(['fire', 'spikes'], $this->types($layout['traps']));

            $occupied = [];
            foreach ([$layout['exit'], ...$layout['enemies'], ...$layout['pickups'], ...$layout['traps']] as $entity) {
                $key = $entity['x'].':'.$entity['y'];
                $this->assertArrayHasKey($key, $distances);
                $this->assertArrayNotHasKey($key, $occupied, 'Gameplay entities must not overlap.');
                $this->assertSame('floor', $layout['grid'][$entity['y']][$entity['x']]['type']);
                $this->assertSame($entity['floor'], $layout['grid'][$entity['y']][$entity['x']]['floor']);
                $this->assertGreaterThan(4, hypot($entity['x'] - $layout['spawn']['x'], $entity['y'] - $layout['spawn']['y']));
                $occupied[$key] = true;
            }
            foreach ($layout['decorations'] as $decoration) {
                $this->assertArrayNotHasKey($decoration['x'].':'.$decoration['y'], $occupied, 'Decorations must leave objectives and combat positions clear.');
            }

            $sigils = array_values(array_filter($layout['pickups'], fn (array $pickup): bool => $pickup['type'] === 'sigil'));
            $this->assertCount(3, $sigils);
            $this->assertCount(3, array_unique(array_column($sigils, 'floor')), 'Three-floor maps should send the player to each floor.');
            $exitDistances = $traversal->distances($layout['grid'], $layout['tileSize'], $layout['exit']);
            $warden = array_values(array_filter($layout['enemies'], fn (array $enemy): bool => $enemy['type'] === 'warden'))[0];
            $this->assertSame($layout['exit']['floor'], $warden['floor']);
            $this->assertLessThanOrEqual(7, $exitDistances[$warden['x'].':'.$warden['y']]);
            $this->assertGreaterThanOrEqual(max($distances) - 4, $distances[$layout['exit']['x'].':'.$layout['exit']['y']]);
        }
    }

    public function test_ramp_sides_and_mismatched_floor_heights_cannot_be_crossed(): void
    {
        $floor = ['walkable' => true, 'type' => 'floor', 'floor' => 0, 'elevation' => 0, 'slope' => 0, 'direction' => ['x' => 0, 'y' => 0]];
        $ramp = [...$floor, 'type' => 'vertical-corridor', 'elevation' => 1.25, 'slope' => rad2deg(atan2(10, 16)), 'direction' => ['x' => 1, 'y' => 0]];
        $traversal = new DungeonTraversal;
        $this->assertTrue($traversal->connected($floor, $ramp, 1, 0, 4));
        $this->assertFalse($traversal->connected($floor, $ramp, 0, 1, 4));
        $this->assertFalse($traversal->connected($floor, [...$floor, 'floor' => 10, 'elevation' => 10], 1, 0, 4));
        $this->assertFalse($traversal->connected($floor, null, 1, 0, 4));
    }

    public function test_small_maps_extreme_room_sizes_and_short_tiles_stay_in_bounds(): void
    {
        foreach ([
            ['width' => 15, 'height' => 15, 'floor_count' => 5],
            ['width' => 15, 'height' => 15, 'rooms' => ['min_width' => 99, 'max_width' => 100, 'min_height' => 99, 'max_height' => 100]],
            ['tile_size' => 1, 'floor_count' => 5],
            ['floor_count' => 1, 'rooms' => ['count_per_floor' => 1, 'min_width' => 3, 'max_width' => 3, 'min_height' => 3, 'max_height' => 3]],
        ] as $config) {
            $layout = (new DungeonGenerator)->generate([], $config, 42);
            $this->assertCount($layout['height'], $layout['grid']);
            foreach ($layout['grid'] as $row) {
                $this->assertCount($layout['width'], $row);
                foreach (array_filter($row) as $cell) {
                    $this->assertLessThanOrEqual(60, abs($cell['slope']));
                }
            }
            $this->assertSame(3, $layout['requiredSigils']);
            $reachable = (new DungeonTraversal)->distances($layout['grid'], $layout['tileSize'], $layout['spawn']);
            foreach ([$layout['exit'], ...$layout['pickups']] as $entity) {
                $this->assertArrayHasKey($entity['x'].':'.$entity['y'], $reachable);
            }
        }
    }

    private function types(array $entities): array
    {
        $types = array_values(array_unique(array_column($entities, 'type')));
        sort($types);

        return $types;
    }
}
