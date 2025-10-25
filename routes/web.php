<?php

use App\Http\Controllers\PlayController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoomController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Rutas web generales de la plataforma.
| Las rutas específicas de cada juego se cargan automáticamente desde
| games/{slug}/routes.php por el GameServiceProvider.
|
*/

// Broadcasting Auth - Requiere autenticación (invitados están autenticados)
Broadcast::routes(['middleware' => ['web']]);

Route::get('/', function () {
    return view('home');
})->name('home');

Route::get('/thanks', function () {
    return view('thanks');
})->name('thanks');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Rutas de vista de Juego Activo (FASE 3: Game)
Route::get('/play/{code}', [PlayController::class, 'show'])->name('play.show');

// Ruta de test de WebSocket (solo desarrollo)
Route::get('/test-websocket', function () {
    return view('test-websocket');
})->name('test.websocket');

// Rutas de Salas
Route::prefix('rooms')->name('rooms.')->group(function () {
    // Crear sala (requiere autenticación)
    Route::get('/create', [RoomController::class, 'create'])->name('create')->middleware('auth');
    Route::post('/', [RoomController::class, 'store'])->name('store')->middleware('auth');

    // Unirse a sala (público)
    Route::get('/join', [RoomController::class, 'join'])->name('join');
    Route::post('/join', [RoomController::class, 'joinByCode'])->name('joinByCode');

    // Nombre de invitado (público)
    Route::get('/{code}/guest-name', [RoomController::class, 'guestName'])->name('guestName');
    Route::post('/{code}/guest-name', [RoomController::class, 'storeGuestName'])->name('storeGuestName');

    // Vistas de sala (público con código)
    Route::get('/{code}/lobby', [RoomController::class, 'lobby'])->name('lobby');
    Route::get('/{code}', [RoomController::class, 'show'])->name('show');
    Route::get('/{code}/results', [RoomController::class, 'results'])->name('results');

    // Acciones de sala (requieren autenticación para master)
    Route::post('/{code}/start', [RoomController::class, 'apiStart'])->name('start')->middleware('auth');
    Route::post('/{code}/close', [RoomController::class, 'apiClose'])->name('close')->middleware('auth');
});

// ========================================================================
// OLD DEBUG ROUTES (legacy - mantener por compatibilidad)
// ========================================================================
Route::get('/debug/websocket/{roomCode}', function ($roomCode) {
    $room = \App\Models\Room::where('code', $roomCode)->first();

    if (!$room) {
        abort(404, 'Sala no encontrada');
    }

    // Cargar event config
    $gameSlug = $room->game->slug ?? 'trivia';
    $capabilitiesPath = base_path("games/{$gameSlug}/capabilities.json");
    $capabilities = json_decode(file_get_contents($capabilitiesPath), true);

    // Cargar eventos base
    $baseEventsPath = config_path('game-events.php');
    $baseEvents = require $baseEventsPath;

    // Merge eventos
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

Route::get('/debug/events/{roomCode}', function ($roomCode) {
    $room = \App\Models\Room::where('code', $roomCode)->first();

    if (!$room) {
        return response()->json(['error' => 'Room not found'], 404);
    }

    $match = \App\Models\GameMatch::where('room_id', $room->id)
        ->whereNull('finished_at')
        ->first();

    if (!$match) {
        return response()->json(['error' => 'No active match'], 404);
    }

    return response()->json([
        'room' => [
            'code' => $room->code,
            'name' => $room->name,
            'id' => $room->id,
        ],
        'match' => [
            'id' => $match->id,
            'game_state' => $match->game_state,
        ],
        'websocket' => [
            'channel' => "room.{$room->code}",
            'events_available' => [
                'RoundStartedEvent' => '.game.round.started',
                'RoundEndedEvent' => '.game.round.ended',
                'PlayerActionEvent' => '.game.player.action',
            ],
        ],
        'test_endpoints' => [
            'fire_round_started' => url("/debug/fire-event/{$roomCode}/round-started"),
            'fire_round_ended' => url("/debug/fire-event/{$roomCode}/round-ended"),
            'fire_player_action' => url("/debug/fire-event/{$roomCode}/player-action"),
        ],
    ]);
})->name('debug.events');

Route::get('/debug/fire-event/{roomCode}/{eventType}', function ($roomCode, $eventType) {
    $room = \App\Models\Room::where('code', $roomCode)->first();

    if (!$room) {
        return response()->json(['error' => 'Room not found'], 404);
    }

    $match = \App\Models\GameMatch::where('room_id', $room->id)
        ->whereNull('finished_at')
        ->first();

    if (!$match) {
        return response()->json(['error' => 'No active match'], 404);
    }

    switch ($eventType) {
        case 'round-started':
            $event = new \App\Events\Game\RoundStartedEvent(
                match: $match,
                currentRound: $match->game_state['round_system']['current_round'] ?? 1,
                totalRounds: $match->game_state['round_system']['total_rounds'] ?? 10,
                phase: 'question'
            );
            event($event);

            return response()->json([
                'success' => true,
                'event' => 'RoundStartedEvent',
                'broadcast_as' => 'game.round.started',
                'channel' => "room.{$room->code}",
                'data' => [
                    'current_round' => $match->game_state['round_system']['current_round'] ?? 1,
                    'total_rounds' => $match->game_state['round_system']['total_rounds'] ?? 10,
                    'phase' => 'question',
                ],
            ]);

        case 'round-ended':
            $players = $match->game_state['round_system']['players'] ?? [];
            $results = [];
            $scores = $match->game_state['scoring_system']['scores'] ?? [];

            foreach ($players as $playerId) {
                $results[$playerId] = [
                    'correct' => rand(0, 1) === 1,
                    'points_earned' => rand(0, 1) === 1 ? 100 : 0,
                ];
            }

            $event = new \App\Events\Game\RoundEndedEvent(
                match: $match,
                roundNumber: $match->game_state['round_system']['current_round'] ?? 1,
                results: $results,
                scores: $scores
            );
            event($event);

            return response()->json([
                'success' => true,
                'event' => 'RoundEndedEvent',
                'broadcast_as' => 'game.round.ended',
                'channel' => "room.{$room->code}",
                'data' => [
                    'round_number' => $match->game_state['round_system']['current_round'] ?? 1,
                    'results' => $results,
                    'scores' => $scores,
                ],
            ]);

        case 'player-action':
            $players = $match->game_state['round_system']['players'] ?? [];
            $playerId = $players[0] ?? null;

            if (!$playerId) {
                return response()->json(['error' => 'No players in match'], 400);
            }

            $player = \App\Models\Player::find($playerId);

            if (!$player) {
                return response()->json(['error' => 'Player not found'], 404);
            }

            $event = new \App\Events\Game\PlayerActionEvent(
                match: $match,
                player: $player,
                actionType: 'answer',
                actionData: ['answer' => 0],
                success: true
            );
            event($event);

            return response()->json([
                'success' => true,
                'event' => 'PlayerActionEvent',
                'broadcast_as' => 'game.player.action',
                'channel' => "room.{$room->code}",
                'data' => [
                    'player_id' => $playerId,
                    'player_name' => "Player {$playerId}",
                    'action_type' => 'answer',
                    'action_data' => ['answer' => 0],
                    'success' => true,
                ],
            ]);

        default:
            return response()->json(['error' => 'Unknown event type'], 400);
    }
})->name('debug.fire-event');

require __DIR__.'/auth.php';
