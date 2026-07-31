<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\World;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class MusicSeeder extends Seeder
{
    public function run(): void
    {
        $disk = Storage::disk('extras');

        foreach ($disk->allFiles() as $path) {
            $path = str_replace('\\', '/', $path);
            $segments = explode('/', $path);

            if (($segments[2] ?? null) !== 'music') {
                continue;
            }

            $world = World::where('slug', $segments[0])->firstOrFail();
            $stage = $world->stages()->where('slug', $segments[1])->firstOrFail();

            Asset::updateOrCreate(
                ['key' => str_replace('/', '.', $path)],
                [
                    'type' => 'music',
                    'path' => $path,
                    'world_id' => $world?->id,
                    'world_stage_id' => $stage?->id,
                    'world_stage_level_id' => null,
                ],
            );
        }
    }
}
