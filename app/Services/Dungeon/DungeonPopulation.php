<?php

namespace App\Services\Dungeon;

final class DungeonPopulation
{
    public function populate(array $layout, int $seed, array $definitions = []): array
    {
        $random = new SeededRandom($seed ^ 0x41C64E6D);
        $traversal = new DungeonTraversal;
        $distances = $traversal->distances($layout['grid'], $layout['tileSize'], $layout['spawn']);
        $tiles = [];
        foreach ($distances as $key => $distance) {
            [$x, $y] = array_map('intval', explode(':', $key));
            $cell = $layout['grid'][$y][$x];
            if ($cell['type'] === 'floor' && $distance > 0) {
                $tiles[$key] = ['x' => $x, 'y' => $y, 'floor' => $cell['floor']];
            }
        }
        // Seeded tie breaks keep repeated runs identical without favoring grid axes.
        $safe = fn (string $key): bool => $distances[$key] > 4
            && hypot($tiles[$key]['x'] - $layout['spawn']['x'], $tiles[$key]['y'] - $layout['spawn']['y']) > 4;
        $safeKeys = array_values(array_filter(array_keys($tiles), $safe));
        $keys = $random->shuffle(count($safeKeys) >= 8 ? $safeKeys : array_keys($tiles));
        usort($keys, fn (string $a, string $b): int => $distances[$b] <=> $distances[$a]);
        $available = array_fill_keys($keys, true);
        $exitKey = $keys[0];
        unset($available[$exitKey]);
        $exit = $tiles[$exitKey];
        $exitDistances = $traversal->distances($layout['grid'], $layout['tileSize'], $exit);
        $sigilKeys = [];
        $anchorDistances = [$distances, $exitDistances];
        $usedFloors = [];

        // The final seal sits beside the farthest portal, where its warden waits.
        $best = null;
        $bestScore = -INF;
        foreach (array_keys($available) as $key) {
            if ($tiles[$key]['floor'] !== $exit['floor']) {
                continue;
            }
            $score = -abs($exitDistances[$key] - 4) + $distances[$key] / max(1, $distances[$exitKey]);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $key;
            }
        }
        if ($best !== null) {
            $sigilKeys[] = $best;
            $usedFloors[$tiles[$best]['floor']] = true;
            $anchorDistances[] = $traversal->distances($layout['grid'], $layout['tileSize'], $tiles[$best]);
            unset($available[$best]);
        }

        $rule = collect($definitions['rule'] ?? [])->firstWhere('id', 'gate') ?? [];
        $requiredSigils = max(1, (int) ($rule['required_sigils'] ?? 3));
        while (count($sigilKeys) < $requiredSigils && $available !== []) {
            $best = null;
            $bestScore = -INF;
            foreach (array_keys($available) as $key) {
                $separation = min(array_map(fn (array $map): int => $map[$key], $anchorDistances));
                $score = $separation + (isset($usedFloors[$tiles[$key]['floor']]) ? 0 : $distances[$exitKey] * 0.25);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $key;
                }
            }
            $sigilKeys[] = $best;
            $usedFloors[$tiles[$best]['floor']] = true;
            $anchorDistances[] = $traversal->distances($layout['grid'], $layout['tileSize'], $tiles[$best]);
            unset($available[$best]);
        }
        $guardedSigil = array_shift($sigilKeys);
        usort($sigilKeys, fn (string $a, string $b): int => $distances[$a] <=> $distances[$b]);
        if ($guardedSigil !== null) {
            $sigilKeys[] = $guardedSigil;
        }
        $pickups = [];
        foreach ($sigilKeys as $index => $key) {
            $pickups[] = ['id' => 'sigil-'.($index + 1), 'type' => 'sigil', ...$tiles[$key]];
        }

        // Supply the first weapon early and the stronger staff deeper in the level.
        $weaponDefinitions = array_values(array_filter(
            $definitions['weapon'] ?? [],
            fn (array $definition): bool => isset($definition['pickup_progress']),
        ));
        usort($weaponDefinitions, fn (array $a, array $b): int => ($a['pickup_progress'] ?? 0) <=> ($b['pickup_progress'] ?? 0));
        foreach ($weaponDefinitions as $weaponDefinition) {
            $weapon = $weaponDefinition['id'];
            $progress = (float) ($weaponDefinition['pickup_progress'] ?? 0.5);
            $weaponKeys = array_keys($available);
            usort($weaponKeys, fn (string $a, string $b): int => abs($distances[$a] - $distances[$exitKey] * $progress) <=> abs($distances[$b] - $distances[$exitKey] * $progress));
            if ($weaponKeys === []) {
                break;
            }
            $key = $weaponKeys[0];
            $pickups[] = ['id' => 'weapon-'.$weapon, 'type' => 'weapon', 'weapon' => $weapon, ...$tiles[$key]];
            unset($available[$key]);
        }

        $enemyDefinitions = array_values($definitions['enemy'] ?? []);
        $enemyById = array_column($enemyDefinitions, null, 'id');
        $enemyKeys = array_values(array_filter(array_keys($available), $safe));
        $enemies = [];
        if ($enemyKeys !== []) {
            $guardDistances = $traversal->distances($layout['grid'], $layout['tileSize'], $tiles[$sigilKeys[count($sigilKeys) - 1]]);
            usort($enemyKeys, fn (string $a, string $b): int => $guardDistances[$a] <=> $guardDistances[$b]);
            $key = array_shift($enemyKeys);
            $warden = isset($enemyById['warden']) ? 'warden' : ($enemyDefinitions[0]['id'] ?? 'imp');
            $enemies[] = ['id' => 'enemy-'.$warden, 'type' => $warden, ...$tiles[$key]];
            unset($available[$key]);
        }
        $enemyKeys = $random->shuffle($enemyKeys);
        $enemyCount = min(25, max(3, intdiv(count($tiles), 35)));
        foreach ($enemyKeys as $key) {
            if (count($enemies) >= $enemyCount) {
                break;
            }
            // Leave space to fight and avoid a single crowded spawn room.
            if (array_filter($enemies, fn (array $enemy): bool => $enemy['floor'] === $tiles[$key]['floor'] && hypot($enemy['x'] - $tiles[$key]['x'], $enemy['y'] - $tiles[$key]['y']) < 3) !== []) {
                continue;
            }
            $enemyType = $enemyDefinitions === [] ? 'imp' : $enemyDefinitions[count($enemies) % count($enemyDefinitions)]['id'];
            $enemies[] = ['id' => 'enemy-'.count($enemies), 'type' => $enemyType, ...$tiles[$key]];
            unset($available[$key]);
        }

        $traps = [];
        $trapCount = min(12, max(2, intdiv(count($tiles), 100)));
        $trapDefinitions = array_values($definitions['trap'] ?? []);
        foreach ($random->shuffle(array_keys($available)) as $key) {
            if (count($traps) >= $trapCount) {
                break;
            }
            if (! $safe($key) || $exitDistances[$key] < 3) {
                continue;
            }
            $trapDefinition = $trapDefinitions === [] ? [] : $trapDefinitions[count($traps) % count($trapDefinitions)];
            $traps[] = [
                'id' => 'trap-'.count($traps),
                'type' => $trapDefinition['id'] ?? (count($traps) % 2 === 0 ? 'spikes' : 'fire'),
                'damage' => $trapDefinition['damage'] ?? null,
                'phase' => $random->int(0, 4000) / 1000,
                ...$tiles[$key],
            ];
            unset($available[$key]);
        }

        $supplyCount = max(6, (int) ceil(count($enemies) * 0.9));
        $supplyTypes = array_values(array_map(
            fn (array $definition): string => $definition['id'],
            array_filter($definitions['pickup'] ?? [], fn (array $definition): bool => ($definition['role'] ?? null) === 'supply'),
        )) ?: ['health', 'mana', 'mana', 'armor'];
        $supplyKeys = $random->shuffle(array_keys($available));
        for ($index = 0; $index < min($supplyCount, count($supplyKeys)); $index++) {
            $key = $supplyKeys[$index];
            $pickups[] = ['id' => 'supply-'.$index, 'type' => $supplyTypes[$index % count($supplyTypes)], ...$tiles[$key]];
        }

        return ['seed' => $seed, 'enemies' => $enemies, 'pickups' => $pickups, 'traps' => $traps, 'exit' => $exit, 'requiredSigils' => count($sigilKeys) ?: $requiredSigils];
    }
}
