<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\DungeonController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/loading', [HomeController::class, 'show'])->name('loading');

Route::get('/game/{level:slug}', [DungeonController::class, 'show'])->name('game');

Route::get('/extras/{asset:path}', [AssetController::class, 'show'])->name('assets.show');
