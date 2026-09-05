<?php

namespace App\Http\Controllers;

use App\Services\ExtraAssets;

class ExtraAssetController extends Controller
{
    public function show(string $asset)
    {
        abort_if(str_contains($asset, '..'), 404);

        $disk = ExtraAssets::disk();
        abort_unless($disk->exists($asset), 404);

        return response()->file($disk->path($asset), ['Cache-Control' => 'public, max-age=86400']);
    }
}
