<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Dungeon\DungeonGenerator;
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

            $this->assertCount(10, $verticalCells);
            foreach ($verticalCells as $cell) {
                $this->assertLessThanOrEqual(60, abs($cell['slope']));
            }

            $this->assertCount(20, $layout['decorations']);
            $this->assertCount(20, array_unique(array_map(
                fn (array $decoration): string => $decoration['floor'] . ':' . $decoration['x'] . ':' . $decoration['y'],
                $layout['decorations'],
            )));
        }
    }
}
