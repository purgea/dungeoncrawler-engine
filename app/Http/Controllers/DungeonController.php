<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\Dungeon\DungeonGenerator;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Schema;

class DungeonController extends Controller
{
    public function show(DungeonGenerator $generator): Response
    {
        return Inertia::render('Game', [
            'dungeon' => $generator->generate(),
            'musicAssets' => Asset::where('type', 'music')->get() ?? collect([]),
        ]);
    }
}
