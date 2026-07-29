<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\WorldStageLevel;
use App\Services\Dungeon\DungeonGenerator;
use Inertia\Inertia;

class DungeonController extends Controller
{
    public function show(WorldStageLevel $level, DungeonGenerator $generator)
    {
        $decorationAssets = Asset::where('type', 'decorations')->get();
        return Inertia::render('Game', [
            'dungeon' => $generator->generate($decorationAssets->toArray(), $level->data ?? []),
            'musicAssets' => Asset::where('type', 'music')->get() ?? collect([]),
            'decorationAssets' => $decorationAssets,
            'weaponAssets' => Asset::where('type', 'weapons')->get() ?? collect([]),
        ]);
    }
}
