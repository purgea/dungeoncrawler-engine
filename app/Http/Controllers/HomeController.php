<?php

namespace App\Http\Controllers;

use App\Models\World;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function index()
    {
        return Inertia::render('Home', [
            'world' => World::with('stages.levels')->where('slug', 'the-ashen-realms')->first(),
        ]);
    }

    public function show()
    {
        return Inertia::render('Loading');
    }
}
