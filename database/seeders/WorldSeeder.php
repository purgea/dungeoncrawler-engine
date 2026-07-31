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
                        'floors' => [0, 10, -10],
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
