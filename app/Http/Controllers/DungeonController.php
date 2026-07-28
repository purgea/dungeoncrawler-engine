<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\Dungeon\DungeonGenerator;
use Inertia\Inertia;

class DungeonController extends Controller
{
    public function show(DungeonGenerator $generator)
    {
        $decorationAssets = Asset::where('type', 'decorations')->get();

        return Inertia::render('Game', [
            'dungeon' => $generator->generate($decorationAssets->toArray()),
            'musicAssets' => Asset::where('type', 'music')->get() ?? collect([]),
            'decorationAssets' => $decorationAssets,
            'weaponAssets' => Asset::where('type', 'weapons')->get() ?? collect([]),
        ]);
    }
}
