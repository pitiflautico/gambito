<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\RoomController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Rutas API generales de la plataforma.
| Las rutas específicas de cada juego se cargan automáticamente desde
| games/{slug}/routes.php por el GameServiceProvider.
|
*/

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
