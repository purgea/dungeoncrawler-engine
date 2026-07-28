<?php

namespace Database\Seeders;

use App\Models\Asset;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DecorationsSeeder extends Seeder
{
    public function run(): void
    {
        $disk = Storage::disk('extras');

        foreach ($disk->directories('decorations') as $directory) {
            $placement = basename(str_replace('\\', '/', $directory));

            foreach ($disk->allFiles($directory) as $path) {
                $path = str_replace('\\', '/', $path);
                Asset::updateOrCreate(
                    ['key' => str_replace('/', '.', $path)],
                    [
                        'type' => 'decorations',
                        'placement' => $placement,
                        'path' => $path,
                    ],
                );
            }
        }
    }
}
