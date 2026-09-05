<?php

namespace App\Http\Controllers;

use App\Models\World;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function index()
    {
        $world = World::with(['stages' => fn ($query) => $query->orderBy('id'), 'stages.levels' => fn ($query) => $query->orderBy('id')])
            ->where('slug', 'the-ashen-realms')->first();
        $firstLevel = $world?->stages->flatMap->levels->first();

        return Inertia::render('Home', [
            'world' => $world,
            // Use the level resolver route instead of baking a level slug into the
            // menu. This keeps New Journey working when the first chapter slug
            // changes or a different world is seeded.
            'firstLevelUrl' => route('game.start', ['new' => 1], false),
        ]);
    }

    public function show()
    {
        return Inertia::render('Loading');
    }
}
