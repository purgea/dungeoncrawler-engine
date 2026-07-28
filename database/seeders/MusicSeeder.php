<?php

namespace Database\Seeders;

use App\Models\Asset;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class MusicSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Storage::disk('extras')->allFiles('music') as $path) {
            $path = str_replace('\\', '/', $path);
            Asset::updateOrCreate(
                ['key' => str_replace('/', '.', $path)],
                ['type' => 'music', 'path' => $path],
            );
        }
    }
}
