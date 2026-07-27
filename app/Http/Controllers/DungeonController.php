<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Dungeon\DungeonGenerator;
use Inertia\Inertia;
use Inertia\Response;

final class DungeonController extends Controller
{
    public function show(DungeonGenerator $generator): Response
    {
        return Inertia::render('Game', [
            'dungeon' => $generator->generate(),
        ]);
    }
}
