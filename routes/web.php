<?php

use App\Http\Controllers\DungeonController;
use App\Http\Controllers\ExtraAssetController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/loading', [HomeController::class, 'show'])->name('loading');

Route::get('/game', [DungeonController::class, 'start'])->name('game.start');
Route::get('/game/{level:slug}', [DungeonController::class, 'show'])->name('game');

Route::get('/extras/{asset:path}', [ExtraAssetController::class, 'show'])->where('asset', '.*')->name('assets.show');
