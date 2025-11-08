<?php

use Games\Uno\UnoController;
use Illuminate\Support\Facades\Route;

// Web Routes - Vista del juego
Route::prefix('uno')->name('uno.')->middleware('web')->group(function () {
    Route::get('/{roomCode}', [UnoController::class, 'game'])->name('game');
});

// API Routes - Acciones del juego
Route::prefix('api/uno')->name('api.uno.')->middleware('api')->group(function () {
    Route::post('/{roomCode}/action', [UnoController::class, 'action'])->name('action');
    Route::get('/{roomCode}/state', [UnoController::class, 'getState'])->name('state');
});
