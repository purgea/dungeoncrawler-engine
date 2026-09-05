<?php

namespace App\Services\Dungeon;

/** The same cardinal connections the player can use, including ramp entrances. */
final class DungeonTraversal
{
    /** @return array<string, int> Distances in tiles, indexed by "x:y". */
    public function distances(array $grid, int $tileSize, array $origin): array
    {
        $distances = [$origin['x'].':'.$origin['y'] => 0];
        $queue = [[$origin['x'], $origin['y']]];

        for ($cursor = 0; $cursor < count($queue); $cursor++) {
            [$x, $y] = $queue[$cursor];
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nextX = $x + $dx;
                $nextY = $y + $dy;
                $key = $nextX.':'.$nextY;
                if (isset($distances[$key]) || ! $this->connected($grid[$y][$x] ?? null, $grid[$nextY][$nextX] ?? null, $dx, $dy, $tileSize)) {
                    continue;
                }
                $distances[$key] = $distances[$x.':'.$y] + 1;
                $queue[] = [$nextX, $nextY];
            }
        }

        return $distances;
    }

    public function connected(?array $from, ?array $to, int $dx, int $dy, int $tileSize): bool
    {
        if (! ($from['walkable'] ?? false) || ! ($to['walkable'] ?? false)) {
            return false;
        }

        foreach ([$from, $to] as $cell) {
            if ($cell['type'] === 'vertical-corridor' && $dx * $cell['direction']['x'] + $dy * $cell['direction']['y'] === 0) {
                return false;
            }
        }

        return abs($this->edgeElevation($from, $dx, $dy, $tileSize) - $this->edgeElevation($to, -$dx, -$dy, $tileSize)) < 0.01;
    }

    private function edgeElevation(array $cell, int $dx, int $dy, int $tileSize): float
    {
        $direction = $cell['direction'];

        return $cell['elevation'] + tan(deg2rad($cell['slope'])) * $tileSize / 2 * ($dx * $direction['x'] + $dy * $direction['y']);
    }
}
