<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use Illuminate\Support\Facades\Storage;

class AssetController extends Controller
{
    public function show(Asset $asset)
    {        
        return response()->file(Storage::disk('extras')->path($asset->path));
    }
}
