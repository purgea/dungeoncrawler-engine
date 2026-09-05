<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final class ExtraAssets
{
    public static function disk(): Filesystem
    {
        try {
            return Storage::disk('extras');
        } catch (InvalidArgumentException) {
            // NativePHP supplies this disk in the desktop runtime. The fallback
            // keeps HTTP development and database seeding usable without adding
            // an application-owned filesystem definition.
            return Storage::build([
                'driver' => 'local',
                'root' => env('NATIVEPHP_EXTRAS_PATH', base_path('extras')),
                'throw' => false,
            ]);
        }
    }
}
