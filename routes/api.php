<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\PictionaryController;
use App\Http\Controllers\RoomController;
use Illuminate\Support\Facades\Route;

// API de Juegos
Route::prefix('games')->name('api.games.')->group(function () {
    Route::get('/', [GameController::class, 'apiIndex'])->name('index');
    Route::get('/{slug}', [GameController::class, 'apiShow'])->name('show');
});

// API de Salas
Route::prefix('rooms')->name('api.rooms.')->group(function () {
    Route::get('/{code}/stats', [RoomController::class, 'apiStats'])->name('stats');
    Route::post('/{code}/leave', [RoomController::class, 'apiLeave'])->name('leave');
});

// API de Pictionary (eventos de canvas en tiempo real)
Route::prefix('pictionary')->name('api.pictionary.')->group(function () {
    Route::post('/draw', [PictionaryController::class, 'broadcastDraw'])->name('draw');
    Route::post('/clear', [PictionaryController::class, 'broadcastClear'])->name('clear');
    Route::post('/player-answered', [PictionaryController::class, 'playerAnswered'])->name('player-answered');
    Route::post('/confirm-answer', [PictionaryController::class, 'confirmAnswer'])->name('confirm-answer');
    Route::post('/advance-phase', [PictionaryController::class, 'advancePhase'])->name('advance-phase');
    Route::post('/get-word', [PictionaryController::class, 'getWord'])->name('get-word');
});
