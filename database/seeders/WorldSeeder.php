<?php

namespace Database\Seeders;

use App\Models\World;
use Illuminate\Database\Seeder;

final class WorldSeeder extends Seeder
{
    public function run(): void
    {
        $world = World::updateOrCreate(
            ['slug' => 'the-ashen-realms'],
            [
                'name' => 'The Ashen Realms',
                'description' => 'A fallen kingdom where ancient magic stirs beneath forgotten stone.',
            ],
        );

        $stage = $world->stages()->updateOrCreate(
            ['slug' => 'the-ruined-marches'],
            [
                'name' => 'The Ruined Marches',
                'description' => 'Cross the haunted frontier and reclaim the road to the old citadel.',
                'lighting' => [
                    'scene' => [
                        'ambient' => [0.24, 0.28, 0.36],
                        'exposure' => 1.1,
                        'fog' => [
                            'type' => 'linear',
                            'color' => [0.025, 0.032, 0.045],
                            'density' => 0,
                            'start' => 32,
                            'end' => 150,
                        ],
                    ],
                    'camera' => [
                        'clear_color' => [0.025, 0.032, 0.045],
                        'tone_mapping' => 'neutral',
                    ],
                    'player' => [
                        'light' => [
                            'color' => [0.58, 0.68, 0.92],
                            'intensity' => 0.55,
                            'range_tiles' => 1.65,
                            'falloff' => 'inverse_squared',
                            'cast_shadows' => false,
                            'position' => [0, -0.35, 0],
                        ],
                    ],
                    'torch' => [
                        'light' => [
                            'color' => [1, 0.3, 0.06],
                            'intensity' => 1.45,
                            'range_tiles' => 1.05,
                            'falloff' => 'inverse_squared',
                            'cast_shadows' => false,
                        ],
                        'flicker' => [
                            'enabled' => true,
                            'base' => 0.94,
                            'frequency_a' => 8.1,
                            'amplitude_a' => 0.035,
                            'frequency_b' => 13.7,
                            'amplitude_b' => 0.025,
                        ],
                    ],
                    'enemy' => [
                        'light' => [
                            'color' => [0.35, 0.05, 0.2],
                            'intensity' => 0.8,
                            'range_tiles' => 1,
                            'falloff' => 'linear',
                            'cast_shadows' => false,
                        ],
                    ],
                    'materials' => [
                        'dungeon' => [
                            'ambient' => [0.4, 0.42, 0.46],
                        ],
                        'door' => [
                            'ambient' => [0.34, 0.28, 0.17],
                            'emissive' => [0.1, 0.06, 0.025],
                        ],
                        'recess' => [
                            'ambient' => [0.16, 0.14, 0.12],
                        ],
                        'arch' => [
                            'ambient' => [0.44, 0.44, 0.42],
                        ],
                        'torch_wood' => [
                            'ambient' => [0.08, 0.035, 0.015],
                        ],
                        'torch_metal' => [
                            'metalness' => 0.65,
                            'shininess' => 70,
                        ],
                        'torch_flame' => [
                            'emissive' => [1, 0.18, 0.015],
                            'emissive_intensity' => 4,
                        ],
                        'enemy' => [
                            'emissive' => [0.18, 0.08, 0.12],
                            'emissive_intensity' => 0.8,
                            'sprite_shadow_alpha' => 0.55,
                            'sprite_shadow_blur' => 13,
                        ],
                        'projectile' => [
                            'emissive' => [0.45, 0.08, 1],
                            'emissive_intensity' => 3,
                        ],
                    ],
                ],
            ],
        );

        foreach (range(1, 4) as $number) {
            $stage->levels()->updateOrCreate(
                ['slug' => "1-{$number}"],
                [
                    'name' => "The Ruined Marches {$number}",
                    'data' => [
                        'width' => 63,
                        'height' => 63,
                        'tile_size' => 4,
                        'wall_height' => 3.3,
                        'floor_count' => 3,
                        'rooms' => [
                            'count_per_floor' => 8,
                            'min_width' => 4,
                            'max_width' => 8,
                            'min_height' => 4,
                            'max_height' => 9,
                            'placement_attempts' => 180,
                        ],
                        'decorations' => ['count' => 20],
                    ],
                ],
            );
        }
    }
}
