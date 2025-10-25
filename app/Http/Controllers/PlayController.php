<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Services\Core\PlayerSessionService;
use App\Services\Core\RoomService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Controlador para la vista del juego (FASE 3).
 *
 * Responsabilidad: Renderizar la vista específica del juego cuando status = PLAYING
 */
class PlayController extends Controller
{
    protected RoomService $roomService;
    protected PlayerSessionService $playerSessionService;

    public function __construct(
        RoomService $roomService,
        PlayerSessionService $playerSessionService
    ) {
        $this->roomService = $roomService;
        $this->playerSessionService = $playerSessionService;
    }

    /**
     * Mostrar la vista del juego activo.
     *
     * FASE 3: Game - El engine ya está inicializado, mostramos la interfaz del juego
     */
    public function show(string $code)
    {
        $code = strtoupper($code);
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            abort(404, 'Sala no encontrada');
        }

        // Cargar relaciones necesarias
        $room->load(['game', 'match.players', 'master']);

        // VALIDACIÓN: Solo permitir acceso si status = PLAYING
        if ($room->status !== Room::STATUS_PLAYING) {
            return redirect()->route('rooms.show', ['code' => $code]);
        }

        // IMPORTANTE: Verificar si el master (creador) está conectado
        if (!$this->roomService->isMasterConnected($room)) {
            \Log::warning("Game - master disconnected, closing room", [
                'code' => $code,
                'master_id' => $room->master_id,
            ]);

            $this->roomService->closeRoom($room);

            return redirect()->route('home')
                ->with('error', 'El creador de la sala se desconectó. La sala ha sido cerrada.');
        }

        // Obtener el jugador actual (guest o autenticado)
        $player = null;
        if (Auth::check()) {
            $player = $room->match->players()->where('user_id', Auth::id())->first();
        } elseif ($this->playerSessionService->hasGuestSession()) {
            $guestData = $this->playerSessionService->getGuestData();
            $player = $room->match->players()->where('session_id', $guestData['session_id'])->first();
        }

        // Si no se encontró jugador, redirigir al lobby
        if (!$player) {
            return redirect()->route('rooms.lobby', ['code' => $code])
                ->with('error', 'Debes unirte a la partida primero');
        }

        $playerId = $player->id;

        // Obtener el rol del jugador desde el motor del juego
        $gameState = $room->match->game_state ?? [];
        $currentDrawerId = $gameState['current_drawer_id'] ?? null;
        $role = ($player->id === $currentDrawerId) ? 'drawer' : 'guesser';

        // Obtener lista de jugadores con sus datos
        $players = $room->match->players->map(function ($p) use ($currentDrawerId, $gameState) {
            return [
                'id' => $p->id,
                'name' => $p->name,
                'score' => $gameState['scores'][$p->id] ?? 0,
                'is_drawer' => ($p->id === $currentDrawerId),
                'is_eliminated' => in_array($p->id, $gameState['eliminated_this_round'] ?? [])
            ];
        });

        // Cargar event_config mergeando base events con eventos del juego
        $gameSlug = $room->game->slug;
        $capabilitiesPath = base_path("games/{$gameSlug}/capabilities.json");

        // Cargar base events
        $baseEventsPath = config_path('game-events.php');
        $baseEventsConfig = require $baseEventsPath;
        $baseEvents = $baseEventsConfig['base_events'] ?? [];

        // Cargar eventos del juego
        $gameEvents = [];
        $channel = 'room.{roomCode}';
        if (file_exists($capabilitiesPath)) {
            $capabilities = json_decode(file_get_contents($capabilitiesPath), true);
            $gameEvents = $capabilities['event_config']['events'] ?? [];
            $channel = $capabilities['event_config']['channel'] ?? 'room.{roomCode}';
        }

        // Merge eventos base + eventos del juego
        $eventConfig = [
            'channel' => $channel,
            'events' => array_merge(
                $baseEvents['events'] ?? [],
                $gameEvents
            ),
        ];

        // Renderizar vista específica del juego usando su namespace
        // CONVENCIÓN: La vista principal del juego SIEMPRE debe llamarse "game.blade.php"
        // Ubicación: games/{slug}/views/game.blade.php
        $gameViewName = "{$gameSlug}::game";
        if (view()->exists($gameViewName)) {
            return view($gameViewName, [
                'code' => $code,
                'room' => $room,
                'match' => $room->match,
                'playerId' => $playerId,
                'role' => $role,
                'eventConfig' => $eventConfig,
            ]);
        }

        // Fallback: Cargar vista genérica si el juego no tiene vista específica
        return view('rooms.show', compact('code', 'room', 'playerId', 'role', 'players', 'eventConfig'));
    }

    // ========================================================================
    // API ENDPOINTS - GAMEPLAY
    // ========================================================================

    /**
     * API: Procesar una acción del juego.
     */
    public function apiProcessAction(Request $request, string $code)
    {
        $code = strtoupper($code);
        
        // 1. Validar request
        $validated = $request->validate([
            'action' => 'required|string',
            'data' => 'sometimes|array',
        ]);

        // 2. Buscar sala usando RoomService (sin consultas directas)
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Sala no encontrada',
            ], 404);
        }

        // 3. Verificar que la sala esté en juego
        if ($room->status !== Room::STATUS_PLAYING || !$room->match) {
            return response()->json([
                'success' => false,
                'message' => 'La sala no está en juego',
            ], 400);
        }

        // 4. Obtener el jugador actual usando helper (sin queries)
        $userId = Auth::id();

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'No estás autenticado',
            ], 401);
        }

        $match = $room->match;
        $player = $match->players->firstWhere('user_id', $userId);

        if (!$player) {
            return response()->json([
                'success' => false,
                'message' => 'No estás registrado en esta sala',
            ], 403);
        }

        \Log::info("[PlayController] Action submitted via API", [
            'room_code' => $code,
            'player_id' => $player->id,
            'action' => $validated['action'],
            'data' => $validated['data'] ?? []
        ]);

        try {
            // 5. Procesar la acción (match delega al engine internamente)
            $result = $match->processAction(
                player: $player,
                action: $validated['action'],
                data: $validated['data'] ?? []
            );

            // 6. Retornar resultado
            return response()->json($result);
        } catch (\Exception $e) {
            \Log::error("[PlayController] Error processing action", [
                'room_code' => $code,
                'action' => $validated['action'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar acción: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API: Avanzar a la siguiente ronda.
     */
    public function apiNextRound(Request $request, string $code)
    {
        $code = strtoupper($code);
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Sala no encontrada',
            ], 404);
        }

        $match = $room->match;

        // Verificar que la sala esté jugando
        if ($room->status !== Room::STATUS_PLAYING) {
            return response()->json([
                'success' => false,
                'message' => 'La sala no está en estado de juego',
            ], 400);
        }

        // VALIDACIÓN DE RONDA: Detectar llamadas obsoletas
        $requestedFromRound = $request->input('from_round');
        $currentRound = $match->game_state['round_system']['current_round'] ?? 1;
        
        if ($requestedFromRound && $requestedFromRound < $currentRound) {
            \Log::info('⏭️ [NextRound] Obsolete request detected', [
                'room_code' => $code,
                'match_id' => $match->id,
                'requested_from_round' => $requestedFromRound,
                'current_round' => $currentRound,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Solicitud obsoleta - la ronda ya avanzó',
                'obsolete' => true,
                'current_round' => $currentRound,
            ]);
        }

        // PROTECCIÓN CONTRA RACE CONDITION
        // Múltiples jugadores van a llamar este endpoint simultáneamente cuando termine el countdown.
        // Solo el primero debe ejecutarlo, los demás reciben confirmación de que ya se está procesando.
        if (!$match->acquireRoundLock()) {
            \Log::info('⏭️ [NextRound] Already processing (race condition prevented)', [
                'room_code' => $code,
                'match_id' => $match->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Ronda ya está siendo procesada',
                'already_processing' => true,
            ]);
        }

        try {
            \Log::info('🔄 [NextRound] Starting new round', [
                'room_code' => $code,
                'match_id' => $match->id,
                'from_round' => $requestedFromRound,
                'current_round' => $currentRound
            ]);

            // Llamar al método handleNewRound del engine
            $engine = $match->getEngine();
            $engine->handleNewRound($match);

            // Refrescar match para obtener la nueva ronda
            $match->refresh();

            \Log::info('✅ [NextRound] New round started successfully', [
                'room_code' => $code,
                'match_id' => $match->id,
                'new_round' => $match->game_state['round_system']['current_round'] ?? 1
            ]);

            // Liberar el lock
            $match->releaseRoundLock();

            return response()->json([
                'success' => true,
                'message' => 'Nueva ronda iniciada',
                'current_round' => $match->game_state['round_system']['current_round'] ?? 1,
            ]);
        } catch (\Exception $e) {
            // Liberar el lock en caso de error
            $match->releaseRoundLock();
            
            \Log::error("❌ [NextRound] Error starting new round: {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'message' => 'Error al iniciar nueva ronda: ' . $e->getMessage(),
            ], 500);
        }
    }
}
