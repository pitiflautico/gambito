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

// Todas las rutas de debug necesitan middleware web para sesiones
Route::middleware(['web'])->group(function () {

// ========================================================================
// GAME EVENTS DEBUG PANEL
// ========================================================================

/**
 * Panel visual para debugging de eventos del juego
 * URL: /debug/game-events/{roomCode}
 *
 * AUTO-CREA un player de testing para cada sesión que acceda al panel.
 * Esto permite abrir múltiples pestañas del navegador para testear Presence Channel.
 */
Route::get('/game-events/{roomCode}', function ($roomCode) {
    $room = Room::where('code', $roomCode)->firstOrFail();
    $match = $room->match;

    if (!$match) {
        abort(404, 'No hay partida activa en esta sala');
    }

    // AUTO-CREAR PLAYER DE TESTING
    $sessionId = request()->session()->getId();
    $user = auth()->user();

    $player = \App\Models\Player::where('match_id', $match->id)
        ->where(function ($query) use ($user, $sessionId) {
            $query->where('user_id', $user?->id)
                  ->orWhere('session_id', $sessionId);
        })
        ->first();

    // Si no existe, crear uno
    if (!$player) {
        $playerNumber = \App\Models\Player::where('match_id', $match->id)->count() + 1;

        $player = \App\Models\Player::create([
            'match_id' => $match->id,
            'session_id' => $sessionId,
            'user_id' => $user?->id,
            'name' => "Debug Player {$playerNumber}",
            'role' => 'player',
        ]);

        logger()->info("🧪 [DEBUG] Auto-created debug player", [
            'player_id' => $player->id,
            'player_name' => $player->name,
            'session_id' => $sessionId,
            'room_code' => $roomCode,
        ]);
    }

    $baseEventsConfig = require config_path('game-events.php');
    $baseEvents = $baseEventsConfig['base_events']['events'] ?? [];

    return view('debug.events', compact('roomCode', 'room', 'match', 'baseEvents', 'player'));
})->name('debug.game-events.panel');

/**
 * API: Reset Room (eliminar players y resetear game_state)
 * POST /debug/game-events/{roomCode}/reset
 */
Route::post('/game-events/{roomCode}/reset', function ($roomCode) {
    $room = Room::where('code', $roomCode)->firstOrFail();
    $match = $room->match;

    if (!$match) {
        return response()->json(['success' => false, 'error' => 'No match found'], 404);
    }

    try {
        // Eliminar players
        $match->players()->delete();

        // Resetear game_state
        $match->update(['game_state' => []]);

        // Resetear status de la sala
        $room->update(['status' => 'waiting']);

        return response()->json([
            'success' => true,
            'message' => 'Room reset successfully',
            'room_code' => $room->code,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => config('app.debug') ? $e->getTraceAsString() : null,
        ], 400);
    }
})->name('debug.game-events.reset');

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

}); // End of web middleware group
