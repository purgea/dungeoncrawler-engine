<?php

use App\Http\Controllers\DungeonController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Loading');
});

Route::get('/game', [DungeonController::class, 'show'])->name('game');
