<?php

namespace App\Services\Dungeon;

use InvalidArgumentException;

final class DungeonPopulation
{
    public function populate(array $layout, int $seed, array $definitions, array $populationConfig): array
    {
        $randomSalt = (int) $populationConfig['random_salt'];
        $safeDistance = (int) $populationConfig['safe_distance'];
        $guardDistance = (int) $populationConfig['guard_distance'];
        $floorDiversityBonus = (float) $populationConfig['floor_diversity_bonus'];
        $enemySpacing = (float) $populationConfig['enemy_spacing'];
        $exitDistanceMinimum = (int) $populationConfig['exit_distance_minimum'];
        $wardenId = (string) $populationConfig['warden_id'];
        $sigilId = (string) $populationConfig['sigil_id'];
        $enemyCountConfig = $populationConfig['enemy_count'];
        $enemyCountMinimum = (int) $enemyCountConfig['minimum'];
        $enemyCountMaximum = (int) $enemyCountConfig['maximum'];
        $tilesPerEnemy = (int) $enemyCountConfig['tiles_per_enemy'];
        $trapCountConfig = $populationConfig['trap_count'];
        $trapCountMinimum = (int) $trapCountConfig['minimum'];
        $trapCountMaximum = (int) $trapCountConfig['maximum'];
        $tilesPerTrap = (int) $trapCountConfig['tiles_per_trap'];
        $trapConfig = $populationConfig['trap'];
        $wallMount = (string) $trapConfig['wall_mount'];
        $wallTrapLimitDivisor = (int) $trapConfig['wall_limit_divisor'];
        $wallTrapLimitMinimum = (int) $trapConfig['wall_limit_minimum'];
        $spikeId = (string) $trapConfig['spike_id'];
        $trapPhaseMinimum = (int) $trapConfig['phase_minimum'];
        $trapPhaseMaximum = (int) $trapConfig['phase_maximum'];
        $trapPhaseScale = (int) $trapConfig['phase_scale'];
        $supplyCountConfig = $populationConfig['supply_count'];
        $supplyCountMinimum = (int) $supplyCountConfig['minimum'];
        $supplyEnemyRatio = (float) $supplyCountConfig['enemy_ratio'];
        $this->validateConfiguration(
            $randomSalt,
            $safeDistance,
            $guardDistance,
            $floorDiversityBonus,
            $enemySpacing,
            $exitDistanceMinimum,
            $enemyCountMinimum,
            $enemyCountMaximum,
            $tilesPerEnemy,
            $trapCountMinimum,
            $trapCountMaximum,
            $tilesPerTrap,
            $wallTrapLimitDivisor,
            $wallTrapLimitMinimum,
            $trapPhaseMinimum,
            $trapPhaseMaximum,
            $trapPhaseScale,
            $supplyCountMinimum,
            $supplyEnemyRatio,
        );
        $random = new SeededRandom($seed ^ $randomSalt);
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
        $safe = fn (string $key): bool => $distances[$key] > $safeDistance
            && hypot($tiles[$key]['x'] - $layout['spawn']['x'], $tiles[$key]['y'] - $layout['spawn']['y']) > $safeDistance;
        $safeKeys = array_values(array_filter(array_keys($tiles), $safe));
        if ($safeKeys === []) {
            throw new InvalidArgumentException('Dungeon population requires at least one safe tile.');
        }
        $keys = $random->shuffle($safeKeys);
        usort($keys, fn (string $a, string $b): int => $distances[$b] <=> $distances[$a]);
        if ($keys === []) {
            throw new InvalidArgumentException('Dungeon population requires at least one traversable tile.');
        }
        $available = array_fill_keys($keys, true);
        $exitKey = $keys[0];
        unset($available[$exitKey]);
        $exit = $tiles[$exitKey];
        $exitDistances = $traversal->distances($layout['grid'], $layout['tileSize'], $exit);
        $sigilKeys = [];
        $anchorDistances = [$distances, $exitDistances];
        $usedFloors = [];

        $gateRule = $this->findDefinition($definitions, 'rule', 'gate');
        $requiredSigils = (int) $gateRule['required_sigils'];
        $sigilDefinition = $this->findDefinition($definitions, 'pickup', $sigilId);

        // The final seal sits beside the farthest portal, where its warden waits.
        $best = null;
        $bestScore = -INF;
        foreach (array_keys($available) as $key) {
            if ($tiles[$key]['floor'] !== $exit['floor']) {
                continue;
            }
            $score = -abs($exitDistances[$key] - $guardDistance) + $distances[$key] / $distances[$exitKey];
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $key;
            }
        }
        if ($best === null) {
            throw new InvalidArgumentException('Dungeon population could not place the guarded sigil.');
        }
        $sigilKeys[] = $best;
        $usedFloors[$tiles[$best]['floor']] = true;
        $anchorDistances[] = $traversal->distances($layout['grid'], $layout['tileSize'], $tiles[$best]);
        unset($available[$best]);

        if ($requiredSigils <= 0) {
            throw new InvalidArgumentException('The gate definition must require at least one sigil.');
        }
        while (count($sigilKeys) < $requiredSigils) {
            if ($available === []) {
                throw new InvalidArgumentException('Dungeon population does not have enough tiles for the configured sigils.');
            }
            $best = null;
            $bestScore = -INF;
            foreach (array_keys($available) as $key) {
                $separation = min(array_map(fn (array $map): int => $map[$key], $anchorDistances));
                $score = $separation + (array_key_exists($tiles[$key]['floor'], $usedFloors) ? 0 : $distances[$exitKey] * $floorDiversityBonus);
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
        if ($guardedSigil === null) {
            throw new InvalidArgumentException('Dungeon population lost the guarded sigil.');
        }
        usort($sigilKeys, fn (string $a, string $b): int => $distances[$a] <=> $distances[$b]);
        $sigilKeys[] = $guardedSigil;
        $pickups = [];
        foreach ($sigilKeys as $index => $key) {
            $pickups[] = ['id' => $sigilId.'-'.($index + 1), 'type' => $sigilDefinition['id'], ...$tiles[$key]];
        }

        // Supply the first weapon early and the stronger staff deeper in the level.
        $weaponDefinitions = array_values(array_filter(
            $definitions['weapon'],
            fn (array $definition): bool => array_key_exists('pickup_progress', $definition),
        ));
        usort($weaponDefinitions, fn (array $a, array $b): int => $a['pickup_progress'] <=> $b['pickup_progress']);
        foreach ($weaponDefinitions as $weaponDefinition) {
            $weapon = $weaponDefinition['id'];
            $progress = (float) $weaponDefinition['pickup_progress'];
            $weaponKeys = array_keys($available);
            usort($weaponKeys, fn (string $a, string $b): int => abs($distances[$a] - $distances[$exitKey] * $progress) <=> abs($distances[$b] - $distances[$exitKey] * $progress));
            if ($weaponKeys === []) {
                throw new InvalidArgumentException("Dungeon population could not place weapon {$weapon}.");
            }
            $key = $weaponKeys[0];
            $pickups[] = ['id' => 'weapon-'.$weapon, 'type' => 'weapon', 'weapon' => $weapon, ...$tiles[$key]];
            unset($available[$key]);
        }

        $enemyDefinitions = array_values($definitions['enemy']);
        $enemyById = array_column($enemyDefinitions, null, 'id');
        $enemyKeys = array_values(array_filter(array_keys($available), $safe));
        $enemies = [];
        if ($enemyDefinitions === []) {
            throw new InvalidArgumentException('Dungeon population requires enemy definitions.');
        }
        if (! array_key_exists($wardenId, $enemyById)) {
            throw new InvalidArgumentException("Dungeon population requires the configured warden definition {$wardenId}.");
        }
        if ($enemyKeys === []) {
            throw new InvalidArgumentException('Dungeon population could not place the warden.');
        }
        $guardDistances = $traversal->distances($layout['grid'], $layout['tileSize'], $tiles[$sigilKeys[count($sigilKeys) - 1]]);
        usort($enemyKeys, fn (string $a, string $b): int => $guardDistances[$a] <=> $guardDistances[$b]);
        $key = array_shift($enemyKeys);
        $enemies[] = ['id' => 'enemy-'.$wardenId, 'type' => $wardenId, ...$tiles[$key]];
        unset($available[$key]);
        $enemyKeys = $random->shuffle($enemyKeys);
        // Keep encounters frequent throughout the dungeon while retaining
        // enough spacing for enemies to navigate and surround the player.
        $enemyCount = min($enemyCountMaximum, max($enemyCountMinimum, intdiv(count($tiles), $tilesPerEnemy)));
        foreach ($enemyKeys as $key) {
            if (count($enemies) >= $enemyCount) {
                break;
            }
            // Leave space to fight and avoid a single crowded spawn room.
            if (array_filter($enemies, fn (array $enemy): bool => $enemy['floor'] === $tiles[$key]['floor'] && hypot($enemy['x'] - $tiles[$key]['x'], $enemy['y'] - $tiles[$key]['y']) < $enemySpacing) !== []) {
                continue;
            }
            $enemyType = $enemyDefinitions[count($enemies) % count($enemyDefinitions)]['id'];
            $enemies[] = ['id' => 'enemy-'.count($enemies), 'type' => $enemyType, ...$tiles[$key]];
            unset($available[$key]);
        }
        if (count($enemies) < $enemyCount) {
            throw new InvalidArgumentException('Dungeon population could not place the configured number of enemies.');
        }

        $traps = [];
        $trapCount = min($trapCountMaximum, max($trapCountMinimum, intdiv(count($tiles), $tilesPerTrap)));
        $trapDefinitions = array_values($definitions['trap']);
        $wallTrapDefinitions = array_values(array_filter($trapDefinitions, fn (array $definition): bool => $definition['mount'] === $wallMount));
        $spikeDefinition = $this->findDefinition($definitions, 'trap', $spikeId);
        $wallTrapLimit = max($wallTrapLimitMinimum, intdiv($trapCount, $wallTrapLimitDivisor));
        if ($wallTrapLimit > 0 && $wallTrapDefinitions === []) {
            throw new InvalidArgumentException('Dungeon population requires at least one wall trap definition.');
        }
        $wallTrapPlaced = 0;

        foreach ($random->shuffle(array_keys($available)) as $key) {
            if (count($traps) >= $trapCount || $wallTrapPlaced >= $wallTrapLimit) {
                break;
            }
            if (! $safe($key) || $exitDistances[$key] < $exitDistanceMinimum) {
                continue;
            }
            $tile = $tiles[$key];
            $wallSide = $this->wallSide($layout['grid'], $tile['x'], $tile['y'], $tile['floor']);
            if ($wallSide === null) {
                continue;
            }
            $trapDefinition = $wallTrapDefinitions[$wallTrapPlaced % count($wallTrapDefinitions)];
            $traps[] = [
                'id' => 'trap-'.count($traps),
                'type' => $trapDefinition['id'],
                'damage' => $trapDefinition['damage'],
                'phase' => $random->int($trapPhaseMinimum, $trapPhaseMaximum) / $trapPhaseScale,
                'wall_side' => $wallSide,
                ...$tile,
            ];
            unset($available[$key]);
            $wallTrapPlaced++;
        }

        foreach ($random->shuffle(array_keys($available)) as $key) {
            if (count($traps) >= $trapCount) {
                break;
            }
            if (! $safe($key) || $exitDistances[$key] < $exitDistanceMinimum) {
                continue;
            }
            $traps[] = [
                'id' => 'trap-'.count($traps),
                'type' => $spikeDefinition['id'],
                'damage' => $spikeDefinition['damage'],
                'phase' => $trapPhaseMinimum / $trapPhaseScale,
                ...$tiles[$key],
            ];
            unset($available[$key]);
        }
        if (count($traps) < $trapCount) {
            throw new InvalidArgumentException('Dungeon population could not place the configured number of traps.');
        }

        // Keep optional powerups meaningful by spacing supply drops out
        // independently of their type.
        $supplyCount = max($supplyCountMinimum, (int) ceil(count($enemies) * $supplyEnemyRatio));
        $supplyTypes = array_values(array_map(
            fn (array $definition): string => $definition['id'],
            array_filter($definitions['pickup'], fn (array $definition): bool => $definition['role'] === 'supply'),
        ));
        if ($supplyCount > 0 && $supplyTypes === []) {
            throw new InvalidArgumentException('Dungeon population requires supply pickup definitions.');
        }
        $supplyKeys = $random->shuffle(array_keys($available));
        for ($index = 0; $index < min($supplyCount, count($supplyKeys)); $index++) {
            $key = $supplyKeys[$index];
            $pickups[] = ['id' => 'supply-'.$index, 'type' => $supplyTypes[$index % count($supplyTypes)], ...$tiles[$key]];
        }
        if (count($supplyKeys) < $supplyCount) {
            throw new InvalidArgumentException('Dungeon population could not place the configured number of supplies.');
        }

        return ['seed' => $seed, 'enemies' => $enemies, 'pickups' => $pickups, 'traps' => $traps, 'exit' => $exit, 'requiredSigils' => count($sigilKeys)];
    }

    private function findDefinition(array $definitions, string $kind, string $id): array
    {
        foreach ($definitions[$kind] as $definition) {
            if ($definition['id'] === $id) {
                return $definition;
            }
        }

        throw new InvalidArgumentException("Missing {$kind} definition: {$id}.");
    }

    private function validateConfiguration(
        int $randomSalt,
        int $safeDistance,
        int $guardDistance,
        float $floorDiversityBonus,
        float $enemySpacing,
        int $exitDistanceMinimum,
        int $enemyCountMinimum,
        int $enemyCountMaximum,
        int $tilesPerEnemy,
        int $trapCountMinimum,
        int $trapCountMaximum,
        int $tilesPerTrap,
        int $wallTrapLimitDivisor,
        int $wallTrapLimitMinimum,
        int $trapPhaseMinimum,
        int $trapPhaseMaximum,
        int $trapPhaseScale,
        int $supplyCountMinimum,
        float $supplyEnemyRatio,
    ): void {
        if ($randomSalt === 0 || $safeDistance < 0 || $guardDistance < 0 || $floorDiversityBonus < 0 || $enemySpacing <= 0 || $exitDistanceMinimum < 0) {
            throw new InvalidArgumentException('Dungeon population distance configuration is invalid.');
        }
        if ($enemyCountMinimum <= 0 || $enemyCountMaximum < $enemyCountMinimum || $tilesPerEnemy <= 0) {
            throw new InvalidArgumentException('Dungeon enemy count configuration is invalid.');
        }
        if ($trapCountMinimum < 0 || $trapCountMaximum < $trapCountMinimum || $tilesPerTrap <= 0 || $wallTrapLimitDivisor <= 0 || $wallTrapLimitMinimum < 0) {
            throw new InvalidArgumentException('Dungeon trap count configuration is invalid.');
        }
        if ($trapPhaseMinimum > $trapPhaseMaximum || $trapPhaseScale <= 0) {
            throw new InvalidArgumentException('Dungeon trap timing configuration is invalid.');
        }
        if ($supplyCountMinimum < 0 || $supplyEnemyRatio < 0) {
            throw new InvalidArgumentException('Dungeon supply count configuration is invalid.');
        }
    }

    private function wallSide(array $grid, int $x, int $y, int $floor): ?string
    {
        foreach ([
            'north' => [0, -1],
            'east' => [1, 0],
            'south' => [0, 1],
            'west' => [-1, 0],
        ] as $side => [$offsetX, $offsetY]) {
            $neighbor = $grid[$y + $offsetY][$x + $offsetX];
            if (! $neighbor || ! $neighbor['walkable'] || $neighbor['floor'] !== $floor) {
                return $side;
            }
        }

        return null;
    }
}
