<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use Illuminate\Support\Facades\Storage;

class AssetController extends Controller
{
    public function show(Asset $asset)
    {
        $disk = Storage::disk('extras');
        abort_unless($disk->exists($asset->path), 404);

        return response()->file($disk->path($asset->path), ['Cache-Control' => 'public, max-age=86400']);
    }
}
