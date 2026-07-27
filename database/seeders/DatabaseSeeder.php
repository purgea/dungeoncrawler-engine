<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Asset;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->seedAssets();

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }

    private function seedAssets(): void
    {
        $disk = Storage::disk('extras');

        foreach ($disk->allFiles() as $path) {
            $parts = explode('/', str_replace('\\', '/', $path));
            $type = count($parts) > 1 ? $parts[0] : 'other';
            $key = str_replace(['/', '\\'], '.', $path);

            Asset::updateOrCreate(
                ['key' => $key],
                ['type' => $type, 'path' => $path],
            );
        }
    }
}
