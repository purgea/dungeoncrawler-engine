<?php

namespace Database\Seeders;

use App\Models\GameDefinition;
use App\Models\World;
use App\Services\ExtraAssets;
use Illuminate\Database\Seeder;

final class GameDefinitionsSeeder extends Seeder
{
    public function run(): void
    {
        $stage = World::query()->where('slug', 'the-ashen-realms')->firstOrFail()
            ->stages()->where('slug', 'the-ruined-marches')->firstOrFail();

        $definitions = [
            'rule' => [
                ['slug' => 'gate', 'sort_order' => 0, 'data' => ['required_sigils' => 3, 'max_mana' => 100]],
            ],
            'weapon' => [
                ['slug' => 'wand', 'sort_order' => 1, 'data' => [
                    'name' => 'Aether Wand', 'slot' => 1, 'cost' => 0, 'damage' => 22,
                    'cooldown' => 0.3, 'speed' => 34, 'radius' => 0.1, 'range' => 60,
                    'color' => [0.24, 0.7, 1], 'description' => 'Swift arcane bolts · infinite charge',
                    'starting' => true,
                ]],
                ['slug' => 'crossbow', 'sort_order' => 2, 'data' => [
                    'name' => 'Grave Crossbow', 'slot' => 2, 'cost' => 5, 'damage' => 25,
                    'cooldown' => 0.6, 'speed' => 60, 'radius' => 0.075, 'range' => 80,
                    'color' => [0.34, 1, 0.54], 'description' => 'Three spectral bolts · 5 mana',
                    'pickup_progress' => 0.18,
                ]],
                ['slug' => 'emberstaff', 'sort_order' => 3, 'data' => [
                    'name' => 'Ember Staff', 'slot' => 3, 'cost' => 12, 'damage' => 95,
                    'cooldown' => 0.9, 'speed' => 24, 'radius' => 0.25, 'range' => 65,
                    'color' => [1, 0.3, 0.06], 'description' => 'Heavy infernal fireball · 12 mana',
                    'pickup_progress' => 0.5,
                ]],
            ],
            'enemy' => [
                ['slug' => 'imp', 'sort_order' => 1, 'data' => [
                    'name' => 'Ash Imp', 'health' => 52, 'speed' => 4.4, 'radius' => 0.44,
                    'height' => 1.8, 'width' => 1.55, 'sight' => 29, 'range' => 1.65,
                    'damage' => 9, 'windup' => 0.42, 'cooldown' => 1.2,
                ]],
                ['slug' => 'acolyte', 'sort_order' => 2, 'data' => [
                    'name' => 'Hollow Acolyte', 'health' => 72, 'speed' => 2.8, 'radius' => 0.46,
                    'height' => 2.25, 'width' => 1.7, 'sight' => 34, 'range' => 23,
                    'damage' => 13, 'windup' => 0.85, 'cooldown' => 2.8, 'projectile_speed' => 11,
                ]],
                ['slug' => 'warden', 'sort_order' => 3, 'data' => [
                    'name' => 'Iron Warden', 'health' => 190, 'speed' => 2.4, 'radius' => 0.63,
                    'height' => 2.65, 'width' => 2.05, 'sight' => 31, 'range' => 2.15,
                    'damage' => 22, 'windup' => 0.78, 'cooldown' => 1.8,
                ]],
            ],
            'pickup' => [
                ['slug' => 'health', 'sort_order' => 1, 'data' => ['name' => 'Vitality Flask', 'role' => 'supply', 'amount' => 30, 'color' => [0.8, 0.12, 0.16]]],
                ['slug' => 'armor', 'sort_order' => 2, 'data' => ['name' => 'Ward Shard', 'role' => 'supply', 'amount' => 25, 'color' => [0.34, 0.66, 0.76]]],
                ['slug' => 'mana', 'sort_order' => 3, 'data' => ['name' => 'Aether Vial', 'role' => 'supply', 'amount' => 25, 'color' => [0.25, 0.48, 1]]],
                ['slug' => 'sigil', 'sort_order' => 4, 'data' => ['name' => 'Ashen Sigil', 'role' => 'sigil', 'color' => [0.55, 0.9, 0.72]]],
                ['slug' => 'weapon', 'sort_order' => 5, 'data' => ['name' => 'Relic Weapon', 'role' => 'weapon', 'color' => [1, 0.64, 0.2]]],
            ],
            'trap' => [
                ['slug' => 'spikes', 'sort_order' => 1, 'data' => ['name' => 'Rising Spikes', 'damage' => 22, 'color' => [0.48, 0.49, 0.46], 'warning_color' => [0.44, 0.12, 0.03], 'period' => 4]],
                ['slug' => 'fire', 'sort_order' => 2, 'data' => ['name' => 'Cinder Vent', 'damage' => 16, 'color' => [1, 0.21, 0.015], 'warning_color' => [0.44, 0.12, 0.03], 'period' => 4.8]],
            ],
        ];

        $disk = ExtraAssets::disk();
        $definitions['decoration'] = [];
        $definitions['music'] = [];
        $definitions['sound'] = [];

        foreach ($disk->directories('decorations') as $directory) {
            $placement = basename(str_replace('\\', '/', $directory));

            foreach ($disk->allFiles($directory) as $path) {
                $path = str_replace('\\', '/', $path);
                $definitions['decoration'][] = [
                    'slug' => 'decoration.'.str_replace('/', '.', $path),
                    'sort_order' => count($definitions['decoration']),
                    'data' => ['path' => $path, 'placement' => $placement],
                ];
            }
        }

        foreach ($disk->allFiles('weapons') as $path) {
            $path = str_replace('\\', '/', $path);
            $weaponId = pathinfo($path, PATHINFO_FILENAME);
            $weaponIndex = array_search($weaponId, array_column($definitions['weapon'], 'slug'), true);

            if ($weaponIndex !== false) {
                $definitions['weapon'][$weaponIndex]['data']['path'] = $path;
            }
        }

        foreach ($disk->allFiles() as $path) {
            $path = str_replace('\\', '/', $path);
            $segments = explode('/', $path);

            if (($segments[0] ?? null) !== 'the-ashen-realms'
                || ($segments[1] ?? null) !== 'the-ruined-marches'
                || ($segments[2] ?? null) !== 'music') {
                continue;
            }

            $definitions['music'][] = [
                'slug' => 'music.'.str_replace('/', '.', $path),
                'sort_order' => count($definitions['music']),
                'data' => ['path' => $path],
            ];
        }

        foreach ($disk->allFiles('sounds') as $path) {
            $path = str_replace('\\', '/', $path);
            $definitions['sound'][] = [
                'slug' => 'sound.'.str_replace('/', '.', $path),
                'sort_order' => count($definitions['sound']),
                'data' => ['path' => $path],
            ];
        }

        foreach ($definitions as $kind => $entries) {
            $slugs = array_column($entries, 'slug');
            $stage->gameDefinitions()
                ->where('kind', $kind)
                ->when($slugs !== [], fn ($query) => $query->whereNotIn('slug', $slugs))
                ->delete();

            foreach ($entries as $entry) {
                GameDefinition::updateOrCreate(
                    ['world_stage_id' => $stage->id, 'kind' => $kind, 'slug' => $entry['slug']],
                    ['data' => $entry['data'], 'sort_order' => $entry['sort_order']],
                );
            }
        }
    }
}
