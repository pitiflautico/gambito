<?php

use App\Models\Room;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Debug Routes
|--------------------------------------------------------------------------
|
| Rutas para debugging y testing de eventos, módulos y funcionalidades.
| IMPORTANTE: Estas rutas solo deben estar disponibles en entorno de desarrollo.
|
*/

// ========================================================================
// GAME EVENTS DEBUG PANEL
// ========================================================================

/**
 * Panel visual para debugging de eventos del juego
 * URL: /debug/game-events/{roomCode}
 */
Route::get('/game-events/{roomCode}', function ($roomCode) {
    $room = Room::where('code', $roomCode)->firstOrFail();
    $match = $room->match;

    if (!$match) {
        abort(404, 'No hay partida activa en esta sala');
    }

    $baseEventsConfig = require config_path('game-events.php');
    $baseEvents = $baseEventsConfig['base_events']['events'] ?? [];

    return view('debug-game-events', compact('roomCode', 'room', 'match', 'baseEvents'));
})->name('debug.game-events.panel');

/**
 * API: Iniciar juego
 * POST /debug/game-events/{roomCode}/start
 */
Route::post('/game-events/{roomCode}/start', function ($roomCode) {
    $room = Room::where('code', $roomCode)->firstOrFail();
    $match = $room->match;

    if (!$match) {
        return response()->json(['success' => false, 'error' => 'No match found'], 404);
    }

    try {
        $engine = $room->game->getEngine();
        $engine->startGame($match);

        return response()->json([
            'success' => true,
            'message' => 'Game started',
            'game_state' => $match->fresh()->game_state,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => config('app.debug') ? $e->getTraceAsString() : null,
        ], 400);
    }
})->name('debug.game-events.start');

/**
 * API: Avanzar a la siguiente ronda
 * POST /debug/game-events/{roomCode}/next-round
 */
Route::post('/game-events/{roomCode}/next-round', function ($roomCode) {
    $room = Room::where('code', $roomCode)->firstOrFail();
    $match = $room->match;

    if (!$match) {
        return response()->json(['success' => false, 'error' => 'No match found'], 404);
    }

    try {
        $engine = $room->game->getEngine();

        // Simular completar ronda actual y avanzar a la siguiente
        $engine->completeRound($match, []);

        return response()->json([
            'success' => true,
            'message' => 'Round advanced',
            'game_state' => $match->fresh()->game_state,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => config('app.debug') ? $e->getTraceAsString() : null,
        ], 400);
    }
})->name('debug.game-events.next-round');

/**
 * API: Finalizar juego
 * POST /debug/game-events/{roomCode}/end
 */
Route::post('/game-events/{roomCode}/end', function ($roomCode) {
    $room = Room::where('code', $roomCode)->firstOrFail();
    $match = $room->match;

    if (!$match) {
        return response()->json(['success' => false, 'error' => 'No match found'], 404);
    }

    try {
        $engine = $room->game->getEngine();
        $engine->finalize($match);

        return response()->json([
            'success' => true,
            'message' => 'Game ended',
            'game_state' => $match->fresh()->game_state,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => config('app.debug') ? $e->getTraceAsString() : null,
        ], 400);
    }
})->name('debug.game-events.end');

/**
 * API: Obtener estado actual del juego
 * GET /debug/game-events/{roomCode}/state
 */
Route::get('/game-events/{roomCode}/state', function ($roomCode) {
    $room = Room::where('code', $roomCode)->firstOrFail();
    $match = $room->match;

    if (!$match) {
        return response()->json(['success' => false, 'error' => 'No match found'], 404);
    }

    return response()->json([
        'success' => true,
        'room_code' => $room->code,
        'room_status' => $room->status,
        'match_id' => $match->id,
        'game_state' => $match->game_state,
        'players' => $match->players->map(fn($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'is_connected' => $p->is_connected,
        ]),
    ]);
})->name('debug.game-events.state');

// ========================================================================
// LEGACY DEBUG ROUTES (mantener por compatibilidad)
// ========================================================================

Route::get('/websocket/{roomCode}', function ($roomCode) {
    $room = Room::where('code', $roomCode)->first();

    if (!$room) {
        abort(404, 'Sala no encontrada');
    }

    $gameSlug = $room->game->slug ?? 'trivia';
    $capabilitiesPath = base_path("games/{$gameSlug}/capabilities.json");

    if (!file_exists($capabilitiesPath)) {
        abort(404, 'Capabilities file not found');
    }

    $capabilities = json_decode(file_get_contents($capabilitiesPath), true);

    $baseEventsPath = config_path('game-events.php');
    $baseEvents = require $baseEventsPath;

    $eventConfig = [
        'channel' => $capabilities['event_config']['channel'] ?? 'room.{roomCode}',
        'events' => array_merge(
            $baseEvents['events'] ?? [],
            $capabilities['event_config']['events'] ?? []
        ),
    ];

    return view('debug-events', [
        'roomCode' => $roomCode,
        'eventConfig' => $eventConfig,
    ]);
})->name('debug.websocket');
