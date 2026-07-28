<?php

namespace Database\Seeders;

use App\Models\Asset;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class WeaponsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Storage::disk('extras')->allFiles('weapons') as $path) {
            $path = str_replace('\\', '/', $path);
            Asset::updateOrCreate(
                ['key' => str_replace('/', '.', $path)],
                ['type' => 'weapons', 'path' => $path],
            );
        }
    }
}
