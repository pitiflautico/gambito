<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\RoomController;
use Illuminate\Support\Facades\Route;

// API de Juegos
Route::prefix('games')->name('api.games.')->group(function () {
    Route::get('/', [GameController::class, 'apiIndex'])->name('index');
    Route::get('/{slug}', [GameController::class, 'apiShow'])->name('show');
});

// API de Salas
Route::prefix('rooms')->name('api.rooms.')->group(function () {
    Route::post('/{code}/start', [RoomController::class, 'apiStart'])->name('start')->middleware('auth:sanctum');
    Route::get('/{code}/stats', [RoomController::class, 'apiStats'])->name('stats');
    Route::post('/{code}/close', [RoomController::class, 'apiClose'])->name('close')->middleware('auth:sanctum');
});
