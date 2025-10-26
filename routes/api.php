<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\PlayController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\TeamController;
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
    Route::get('/{code}/state', [RoomController::class, 'apiGetState'])->name('state');
});

// API de Partidas (Match Control)
Route::post('/games/{match}/game-ready', [PlayController::class, 'gameReady'])
    ->name('api.matches.game-ready');
Route::post('/games/{match}/start-next-round', [PlayController::class, 'startNextRound'])
    ->name('api.matches.start-next-round');

// API de Salas
Route::prefix('rooms')->name('api.rooms.')->group(function () {
    Route::get('/{code}/stats', [RoomController::class, 'apiStats'])->name('stats');
    Route::post('/{code}/leave', [RoomController::class, 'apiLeave'])->name('leave');

    // Transición Lobby → Game Room
    Route::post('/{code}/ready', [RoomController::class, 'apiReady'])->name('ready');
    Route::post('/{code}/initialize-engine', [RoomController::class, 'apiInitializeEngine'])->name('initialize-engine');

    // Gestión de Rondas
    Route::post('/{code}/next-round', [\App\Http\Controllers\PlayController::class, 'apiNextRound'])->name('next-round');

    // Información del Jugador
    Route::get('/{code}/player-info', [RoomController::class, 'apiGetPlayerInfo'])
        ->middleware(['web'])
        ->name('player-info');

    // Acciones de Juego (genérico para todos los juegos)
    Route::post('/{code}/action', [\App\Http\Controllers\PlayController::class, 'apiProcessAction'])
        ->middleware(['web'])
        ->name('action');

    // Presence Channel tracking
    Route::post('/{code}/presence/check', [RoomController::class, 'checkAllPlayersConnected'])
        ->middleware(['web'])
        ->name('presence.check');
});

// API de Jugadores
Route::get('/players/{id}', function($id) {
    $player = \App\Models\Player::find($id);
    return response()->json($player ? $player->only(['id', 'name']) : ['id' => $id, 'name' => null]);
});

// API de Equipos
Route::prefix('rooms/{roomCode}/teams')->name('api.teams.')->middleware(['web'])->group(function () {
    // Configuración
    Route::post('/enable', [TeamController::class, 'enable'])->name('enable');
    Route::post('/disable', [TeamController::class, 'disable'])->name('disable');
    Route::get('/', [TeamController::class, 'index'])->name('index');
    Route::get('/validate', [TeamController::class, 'validate'])->name('validate');

    // CRUD de equipos
    Route::post('/', [TeamController::class, 'store'])->name('store');
    Route::delete('/{teamId}', [TeamController::class, 'destroy'])->name('destroy');

    // Asignación de jugadores
    Route::post('/assign', [TeamController::class, 'assignPlayer'])->name('assign');
    Route::delete('/players/{playerId}', [TeamController::class, 'removePlayer'])->name('remove');
    Route::post('/balance', [TeamController::class, 'balance'])->name('balance');

    // Configuración avanzada
    Route::put('/self-selection', [TeamController::class, 'updateSelfSelection'])->name('self-selection');
});
Route::get('/game/player/{id}', function($id) { return response()->json(['player' => App\Models\Player::find($id)]); });
