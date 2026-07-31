<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\WorldStageLevel;
use App\Services\Dungeon\DungeonGenerator;
use App\Services\Dungeon\SeededRandom;
use Inertia\Inertia;
use Native\Desktop\Facades\Settings;

class DungeonController extends Controller
{
    public function show(WorldStageLevel $level, DungeonGenerator $generator)
    {
        $stage = $level->stage;

        $decorationAssets = Asset::where('type', 'decorations')->get() ?? [];
        $musicAssets = Asset::where('type', 'music')->where('world_id', $stage->world_id)->where('world_stage_id', $stage->id)->get() ?? [];
        $weaponAssets = Asset::where('type', 'weapons')->get() ?? [];

        $seed = Settings::get('current_level_seed');
        if ($seed === null) {
            $seed = random_int(1, SeededRandom::MAX_SEED);
            Settings::set('current_level_seed', $seed);
        }

        return Inertia::render('Game', [
            'dungeon' => $generator->generate($decorationAssets->toArray(), $level->data ?? [], (int) $seed),
            'musicAssets' => $musicAssets,
            'decorationAssets' => $decorationAssets,
            'weaponAssets' => $weaponAssets,
        ]);
    }
}
