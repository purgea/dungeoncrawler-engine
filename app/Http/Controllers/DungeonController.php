<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\WorldStageLevel;
use App\Services\Dungeon\DungeonGenerator;
use Inertia\Inertia;
use Native\Desktop\Facades\Settings;

class DungeonController extends Controller
{
    public function show(WorldStageLevel $level, DungeonGenerator $generator)
    {
        $decorationAssets = Asset::where('type', 'decorations')->get();
        $seed = Settings::get('current_level_seed');
        if ($seed === null) {
            $seed = random_int(1, PHP_INT_MAX);
            Settings::set('current_level_seed', $seed);
        }

        return Inertia::render('Game', [
            'dungeon' => $generator->generate($decorationAssets->toArray(), $level->data ?? [], (int) $seed),
            'musicAssets' => Asset::where('type', 'music')->get() ?? collect([]),
            'decorationAssets' => $decorationAssets,
            'weaponAssets' => Asset::where('type', 'weapons')->get() ?? collect([]),
        ]);
    }
}
