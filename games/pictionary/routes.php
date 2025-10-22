<?php

use Games\Pictionary\PictionaryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Pictionary Game Routes
|--------------------------------------------------------------------------
|
| Este archivo contiene todas las rutas específicas del juego Pictionary.
| Las rutas se cargan automáticamente por GameServiceProvider con el prefijo
| y nombre adecuados.
|
| Prefijo API: /api/pictionary
| Prefijo Web: /pictionary
|
*/

// ============================================================================
// API Routes - Endpoints del juego
// ============================================================================

Route::prefix('api/pictionary')->name('api.pictionary.')->group(function () {
    // Canvas - Dibujo en tiempo real
    Route::post('/draw', [PictionaryController::class, 'broadcastDraw'])->name('draw');
    Route::post('/clear', [PictionaryController::class, 'broadcastClear'])->name('clear');

    // Gameplay - Mecánicas del juego
    Route::post('/player-answered', [PictionaryController::class, 'playerAnswered'])->name('player-answered');
    Route::post('/confirm-answer', [PictionaryController::class, 'confirmAnswer'])->name('confirm-answer');
    Route::post('/advance-phase', [PictionaryController::class, 'advancePhase'])->name('advance-phase');
    Route::post('/get-word', [PictionaryController::class, 'getWord'])->name('get-word');
});

// ============================================================================
// Web Routes - Páginas del juego
// ============================================================================

Route::prefix('pictionary')->name('pictionary.')->group(function () {
    // Ruta principal del juego
    Route::get('/{roomCode}', [PictionaryController::class, 'game'])->name('game');

    // Demo/Testing (solo desarrollo - TODO: agregar middleware de environment)
    Route::get('/demo', [PictionaryController::class, 'demo'])->name('demo');
});
