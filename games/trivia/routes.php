<?php

use Games\Trivia\TriviaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Trivia Game Routes
|--------------------------------------------------------------------------
|
| Rutas específicas del juego Trivia.
| Las rutas se cargan automáticamente por GameServiceProvider.
|
*/

// API Routes - Para acciones del juego
Route::prefix('api/trivia')->name('api.trivia.')->middleware('api')->group(function () {
    Route::post('/answer', [TriviaController::class, 'answer'])->name('answer');
});

// Web Routes - Para vistas del juego
Route::prefix('trivia')->name('trivia.')->middleware('web')->group(function () {
    Route::get('/{roomCode}', [TriviaController::class, 'game'])->name('game');
});
