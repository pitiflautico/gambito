<?php

use App\Http\Controllers\MentirosoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mentiroso Game Routes
|--------------------------------------------------------------------------
|
| Rutas específicas del juego Mentiroso.
| Estas rutas se cargan automáticamente por el GameServiceProvider.
|
*/

Route::prefix('api/rooms/{roomCode}/game/mentiroso')
    ->name('api.mentiroso.')
    ->middleware(['api'])
    ->group(function () {
        // Submit vote
        Route::post('/vote', [MentirosoController::class, 'submitVote'])
            ->name('vote');
    });
