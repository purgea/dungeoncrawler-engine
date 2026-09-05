<?php

namespace Tests\Feature;

use App\Models\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssetDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_nested_asset_paths_are_served_over_http(): void
    {
        Storage::fake('extras');
        Storage::disk('extras')->put('weapons/test/crossbow.png', 'image-data');
        $asset = Asset::create(['key' => 'test-crossbow', 'type' => 'weapons', 'path' => 'weapons/test/crossbow.png']);

        $this->assertSame('/extras/weapons/test/crossbow.png', $asset->path_url);
        $response = $this->get($asset->path_url)->assertSuccessful();
        $this->assertSame('image-data', file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->get('/extras/unregistered.png')->assertNotFound();
    }

    public function test_missing_registered_assets_return_404(): void
    {
        Storage::fake('extras');
        $asset = Asset::create(['key' => 'missing-crossbow', 'type' => 'weapons', 'path' => 'weapons/missing.png']);

        $this->get($asset->path_url)->assertNotFound();
    }
}
