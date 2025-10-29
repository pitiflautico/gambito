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
                'userId' => $player->user_id, // Necesario para canal privado de eventos
                'eventConfig' => $eventConfig,
            ]);
        }

        // Fallback: Cargar vista genérica si el juego no tiene vista específica
        return view('rooms.show', compact('code', 'room', 'playerId', 'eventConfig'));
    }

    // ========================================================================
    // API ENDPOINTS - LIFECYCLE & SYNCHRONIZATION
    // ========================================================================

    /**
     * API: Notificar que el DOM del jugador está cargado y listo.
     *
     * Este endpoint es llamado por BaseGameClient cuando el frontend termina de
     * cargar el DOM y está listo para recibir eventos. El backend usa esto para
     * coordinar el inicio del juego cuando TODOS los jugadores están listos.
     *
     * Race Condition Protection:
     * - Usa Redis para trackear jugadores con DOM cargado
     * - Solo el último jugador en reportarse iniciará el juego
     * - Emite DomLoadedEvent para notificar progreso a todos los clientes
     * - Cuando todos están listos → Emite GameStartedEvent
     *
     * @param \Illuminate\Http\Request $request
     * @param string $code Room code
     */
    public function apiDomLoaded(Request $request, string $code)
    {
        $code = strtoupper($code);

        try {
            // 1. Buscar sala y validar
            $room = $this->roomService->findRoomByCode($code);

            if (!$room) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sala no encontrada',
                ], 404);
            }

            $match = $room->match;

            if (!$match) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay partida activa en esta sala',
                ], 404);
            }

            // 2. Obtener el jugador actual
            $player = null;
            if (Auth::check()) {
                $player = $match->players()->where('user_id', Auth::id())->first();
            } elseif ($this->playerSessionService->hasGuestSession()) {
                $guestData = $this->playerSessionService->getGuestData();
                $player = $match->players()->where('session_id', $guestData['session_id'])->first();
            }

            if (!$player) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jugador no encontrado en esta partida',
                ], 403);
            }

            \Log::info('📱 [DomLoaded] Player DOM ready', [
                'room_code' => $code,
                'player_id' => $player->id,
                'player_name' => $player->name,
            ]);

            // 3. Usar Cache para trackear jugadores con DOM cargado (transparente: array local o Redis en prod)
            $cacheKey = "match:{$match->id}:dom_ready";
            $cache = \Illuminate\Support\Facades\Cache::store(); // Usa 'array' en local, 'redis' en prod

            // Obtener set actual de jugadores listos (o array vacío)
            $playersReadySet = $cache->get($cacheKey, []);

            // Añadir jugador al set (idempotente)
            if (!in_array($player->id, $playersReadySet)) {
                $playersReadySet[] = $player->id;
                $cache->put($cacheKey, $playersReadySet, now()->addMinutes(5));
            }

            // Contar jugadores con DOM cargado
            $playersReady = count($playersReadySet);
            $totalPlayers = $match->players()->count();

            \Log::info('📊 [DomLoaded] Progress', [
                'room_code' => $code,
                'players_ready' => $playersReady,
                'total_players' => $totalPlayers,
            ]);

            // 4. Emitir evento DomLoadedEvent para actualizar UI de todos los clientes
            event(new \App\Events\Game\DomLoadedEvent(
                $code,
                $player->id,
                $totalPlayers,
                $playersReady
            ));

            // 5. Si todos los jugadores tienen DOM cargado → Iniciar el juego (solo si no ha iniciado ya)
            if ($playersReady >= $totalPlayers) {
                $currentPhase = $match->game_state['phase'] ?? 'waiting';

                // Verificar si el juego ya ha iniciado
                if ($currentPhase === 'starting') {
                    \Log::info('🎮 [DomLoaded] All players ready - Starting game!', [
                        'room_code' => $code,
                        'match_id' => $match->id,
                        'current_phase' => $currentPhase,
                    ]);

                    // Limpiar cache (ya no necesitamos trackear)
                    $cache->forget($cacheKey);

                    // EMITIR GameStartedEvent ANTES de inicializar el engine
                    // Esto notifica al frontend que el juego está por comenzar
                    event(new \App\Events\Game\GameStartedEvent($match, $match->game_state ?? []));

                    \Log::info('📢 [DomLoaded] GameStartedEvent emitted', [
                        'room_code' => $code,
                        'match_id' => $match->id,
                    ]);

                    // INICIALIZAR EL ENGINE DEL JUEGO
                    // Este es el ÚNICO lugar donde se inicializa completamente el engine.
                    // Esto llama a:
                    // 1. engine->initialize($match) - Configura el juego
                    // 2. engine->startGame($match) - Resetea módulos y llama onGameStart()
                    // 3. Actualiza room status a 'playing'
                    // 4. Emite GameInitializedEvent
                    \Log::info('🎮 [DomLoaded] Initializing game engine...', [
                        'room_code' => $code,
                        'match_id' => $match->id,
                    ]);

                    $match->initializeEngine();

                    \Log::info('✅ [DomLoaded] Game engine initialized and started successfully', [
                        'room_code' => $code,
                        'match_id' => $match->id,
                        'phase' => $match->game_state['phase'] ?? 'unknown',
                    ]);
                } else {
                    \Log::info('⏭️  [DomLoaded] Game already started, skipping GameStartedEvent', [
                        'room_code' => $code,
                        'match_id' => $match->id,
                        'current_phase' => $currentPhase,
                    ]);

                    // Limpiar cache de todas formas
                    $cache->forget($cacheKey);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'DOM loaded registered',
                'players_ready' => $playersReady,
                'total_players' => $totalPlayers,
                'all_ready' => $playersReady >= $totalPlayers,
            ], 200);

        } catch (\Exception $e) {
            \Log::error('❌ [DomLoaded] Error processing DOM loaded', [
                'room_code' => $code,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error processing DOM loaded: ' . $e->getMessage(),
            ], 500);
        }
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
                'player_id' => $player->id ?? null,
                'action' => $validated['action'],
                'data' => $validated['data'] ?? [],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
        // Wrapper que busca el room por código y delega a startNextRound()
        $code = strtoupper($code);
        $room = $this->roomService->findRoomByCode($code);

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Sala no encontrada',
            ], 404);
        }

        $match = $room->match;

        if (!$match) {
            return response()->json([
                'success' => false,
                'message' => 'No hay partida activa en esta sala',
            ], 404);
        }

        // Verificar que la sala esté jugando
        if ($room->status !== Room::STATUS_PLAYING) {
            return response()->json([
                'success' => false,
                'message' => 'La sala no está en estado de juego',
            ], 400);
        }

        // Delegar toda la lógica a startNextRound()
        return $this->startNextRound($request, $match);
    }

    /**
     * API: Verificar si el timer expiró y ejecutar acción correspondiente.
     *
     * Legacy mode (sin timer_type): checkTimerAndAutoAdvance() → completa ronda
     * New mode (con timer_type): onTimerExpired() → maneja timers específicos
     *
     * Backward compatible con juegos existentes.
     */
    public function apiCheckTimer(Request $request, string $code)
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

        if (!$match) {
            return response()->json([
                'success' => false,
                'message' => 'No hay partida activa en esta sala',
            ], 404);
        }

        try {
            // Obtener el engine del juego
            $engine = $match->getEngine();

            // Obtener tipo de timer (opcional, para backward compatibility)
            $timerType = $request->input('timer_type');

            // Si se especifica timer_type y el engine soporta onTimerExpired
            if ($timerType && method_exists($engine, 'onTimerExpired')) {
                $result = $engine->onTimerExpired($match, $timerType);

                return response()->json([
                    'success' => true,
                    'message' => "Timer '{$timerType}' handled",
                    'result' => $result,
                ], 200);
            }

            // Fallback: comportamiento legacy (timer de ronda)
            $wasCompleted = $engine->checkTimerAndAutoAdvance($match);

            if ($wasCompleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'Round completed due to timer expiration',
                    'completed' => true,
                ], 200);
            }

            return response()->json([
                'success' => true,
                'message' => 'Timer not expired or already processed',
                'completed' => false,
            ], 200);

        } catch (\Exception $e) {
            \Log::error('[PlayController] Error checking timer', [
                'room_code' => $code,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al verificar timer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Notificar que un jugador se desconectó durante la partida.
     *
     * Este endpoint es llamado por el frontend cuando Laravel Echo
     * detecta que un jugador abandonó el presence channel durante
     * una partida activa.
     */
    public function apiPlayerDisconnected(Request $request, string $code)
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

        if (!$match) {
            return response()->json([
                'success' => false,
                'message' => 'No hay partida activa en esta sala',
            ], 404);
        }

        // Solo procesar si el juego está en fase "playing"
        if ($match->game_state['phase'] !== 'playing') {
            return response()->json([
                'success' => false,
                'message' => 'El juego no está en fase de juego',
            ], 400);
        }

        try {
            $playerId = $request->input('player_id');

            if (!$playerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'player_id es requerido',
                ], 400);
            }

            $player = \App\Models\Player::find($playerId);

            if (!$player) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jugador no encontrado',
                ], 404);
            }

            // Obtener el engine y llamar al método de desconexión
            $engine = $match->getEngine();
            $engine->onPlayerDisconnected($match, $player);

            return response()->json([
                'success' => true,
                'message' => 'Desconexión procesada correctamente',
            ], 200);

        } catch (\Exception $e) {
            \Log::error('[PlayController] Error processing player disconnection', [
                'room_code' => $code,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar desconexión: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Notificar que un jugador se reconectó durante la partida.
     *
     * Este endpoint es llamado por el frontend cuando Laravel Echo
     * detecta que un jugador volvió al presence channel después de
     * haberse desconectado.
     */
    public function apiPlayerReconnected(Request $request, string $code)
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

        if (!$match) {
            return response()->json([
                'success' => false,
                'message' => 'No hay partida activa en esta sala',
            ], 404);
        }

        // Solo procesar si el juego está pausado por desconexión
        if (!isset($match->game_state['paused']) || !$match->game_state['paused']) {
            return response()->json([
                'success' => false,
                'message' => 'El juego no está pausado',
            ], 400);
        }

        try {
            $playerId = $request->input('player_id');

            if (!$playerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'player_id es requerido',
                ], 400);
            }

            $player = \App\Models\Player::find($playerId);

            if (!$player) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jugador no encontrado',
                ], 404);
            }

            // Obtener el engine y llamar al método de reconexión
            $engine = $match->getEngine();
            $engine->onPlayerReconnected($match, $player);

            return response()->json([
                'success' => true,
                'message' => 'Reconexión procesada correctamente',
            ], 200);

        } catch (\Exception $e) {
            \Log::error('[PlayController] Error processing player reconnection', [
                'room_code' => $code,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar reconexión: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ========================================================================
    // API ENDPOINTS - TIMING & RACE CONDITION PROTECTION
    // ========================================================================

    /**
     * API: Notificar que el juego está listo para empezar (con protección contra race conditions).
     *
     * Este endpoint es llamado por el frontend cuando el countdown del
     * GameStartedEvent termina. Usa un lock mechanism para prevenir que múltiples
     * clientes inicien el juego simultáneamente.
     *
     * Race Condition Protection:
     * - Solo el primer cliente en adquirir el lock iniciará el juego
     * - Otros clientes recibirán 200 OK con flag already_processing=true
     * - Todos los clientes se sincronizarán con RoundStartedEvent via WebSocket
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\GameMatch $match
     */
    public function gameReady(Request $request, \App\Models\GameMatch $match)
    {
        try {
            \Log::info('📥 [API] gameReady request received', [
                'match_id' => $match->id,
                'room_code' => $match->room->code,
                'current_phase' => $match->game_state['phase'] ?? 'unknown',
            ]);

            // 1. Validar que el juego está en fase "starting"
            $currentPhase = $match->game_state['phase'] ?? null;

            if ($currentPhase !== 'starting') {
                \Log::warning('⚠️  [API] Invalid phase for game ready', [
                    'match_id' => $match->id,
                    'expected_phase' => 'starting',
                    'actual_phase' => $currentPhase,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Game is not in starting phase',
                    'current_phase' => $currentPhase,
                ], 400);
            }

            // 2. Intentar adquirir lock (solo el primer cliente lo consigue)
            if (!$match->acquireRoundLock()) {
                \Log::info('⏸️  [API] Lock already held, another client is starting the game', [
                    'match_id' => $match->id,
                ]);

                return response()->json([
                    'success' => true,
                    'already_processing' => true,
                    'message' => 'Another client is starting the game, you will receive events shortly',
                ], 200); // 200 OK para evitar errores en consola del navegador
            }

            // 3. Lock adquirido - proceder a iniciar el juego
            try {
                \Log::info('🔒 [API] Lock acquired, starting game', [
                    'match_id' => $match->id,
                ]);

                // Obtener el engine del juego
                $game = $match->room->game;
                $engineClass = $game->getEngineClass();

                if (!$engineClass || !class_exists($engineClass)) {
                    throw new \RuntimeException("Game engine not found for game: {$game->slug}");
                }

                $engine = app($engineClass);

                // Llamar a triggerGameStart() para iniciar el juego
                $engine->triggerGameStart($match);

                \Log::info('✅ [API] Game started successfully', [
                    'match_id' => $match->id,
                    'new_phase' => $match->game_state['phase'] ?? 'unknown',
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Game started',
                    'phase' => $match->game_state['phase'] ?? null,
                ]);

            } finally {
                // 4. SIEMPRE liberar el lock (incluso si hubo excepción)
                $match->releaseRoundLock();
            }

        } catch (\Exception $e) {
            \Log::error('❌ [API] Error starting game', [
                'match_id' => $match->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API: Iniciar siguiente ronda (con protección contra race conditions).
     *
     * Este endpoint es llamado por el frontend cuando el countdown del
     * TimingModule termina. Usa un lock mechanism para prevenir que múltiples
     * clientes avancen la ronda simultáneamente.
     *
     * Race Condition Protection:
     * - Solo el primer cliente en adquirir el lock avanzará la ronda
     * - Otros clientes recibirán 409 Conflict
     * - Todos los clientes se sincronizarán con RoundStartedEvent via WebSocket
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\GameMatch $match
     */
    public function startNextRound(Request $request, \App\Models\GameMatch $match)
    {
        try {
            \Log::info('📥 [API] startNextRound request received', [
                'match_id' => $match->id,
                'room_code' => $match->room->code,
                'current_phase' => $match->game_state['phase'] ?? 'unknown',
            ]);

            // 0. VALIDACIÓN DE RONDA: Detectar llamadas obsoletas
            $requestedFromRound = $request->input('from_round');
            $currentRound = $match->game_state['round_system']['current_round'] ?? 1;

            if ($requestedFromRound && $requestedFromRound < $currentRound) {
                \Log::info('⏭️ [NextRound] Obsolete request detected', [
                    'room_code' => $match->room->code,
                    'match_id' => $match->id,
                    'requested_from_round' => $requestedFromRound,
                    'current_round' => $currentRound,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Solicitud obsoleta - la ronda ya avanzó',
                    'obsolete' => true,
                    'current_round' => $currentRound,
                ], 200);
            }

            // 1. Validar que el juego está en fase correcta
            $currentPhase = $match->game_state['phase'] ?? null;

            // Si el juego ya terminó, retornar éxito sin hacer nada
            if ($currentPhase === 'finished') {
                \Log::info('🏁 [API] Game already finished, ignoring startNextRound request', [
                    'match_id' => $match->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Game already finished',
                    'game_finished' => true,
                ], 200);
            }

            // Aceptar "starting", "playing" o "results"
            // - starting: primer round (después de GameStartedEvent)
            // - playing: siguientes rounds en juegos como Trivia que no usan "results"
            // - results: siguientes rounds en juegos que sí usan fase "results"
            if ($currentPhase !== 'results' && $currentPhase !== 'starting' && $currentPhase !== 'playing') {
                \Log::warning('⚠️  [API] Invalid phase for starting next round', [
                    'match_id' => $match->id,
                    'expected_phases' => ['starting', 'playing', 'results'],
                    'actual_phase' => $currentPhase,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Game is not in valid phase (expected: starting, playing or results)',
                    'current_phase' => $currentPhase,
                ], 400);
            }

            // 2. Intentar adquirir lock (solo el primer cliente lo consigue)
            if (!$match->acquireRoundLock()) {
                \Log::info('⏸️  [API] Lock already held, another client is advancing round', [
                    'match_id' => $match->id,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Another client is already starting the round',
                    'message' => 'You will receive RoundStartedEvent shortly',
                ], 409); // 409 Conflict
            }

            // 3. Lock adquirido - proceder a avanzar la ronda
            try {
                \Log::info('🔒 [API] Lock acquired, advancing to next round', [
                    'match_id' => $match->id,
                ]);

                // DOBLE VERIFICACIÓN: Re-leer match DESPUÉS de adquirir lock
                // Esto detecta si otro request ya avanzó la ronda antes de que adquiriéramos el lock
                $match->refresh();
                $currentRoundAfterLock = $match->game_state['round_system']['current_round'] ?? 1;

                if ($requestedFromRound && $requestedFromRound < $currentRoundAfterLock) {
                    \Log::warning('⏭️ [NextRound] Round already advanced by another client (after lock)', [
                        'match_id' => $match->id,
                        'requested_from_round' => $requestedFromRound,
                        'current_round_after_lock' => $currentRoundAfterLock,
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'La ronda ya fue avanzada por otro cliente',
                        'obsolete' => true,
                        'current_round' => $currentRoundAfterLock,
                    ], 200);
                }

                // Obtener el engine del juego
                $game = $match->room->game;
                $engineClass = $game->getEngineClass();

                if (!$engineClass || !class_exists($engineClass)) {
                    throw new \RuntimeException("Game engine not found for game: {$game->slug}");
                }

                $engine = app($engineClass);

                // Avanzar a la siguiente ronda
                $engine->handleNewRound($match);

                \Log::info('✅ [API] Next round started successfully', [
                    'match_id' => $match->id,
                    'new_round' => $match->game_state['round_system']['current_round'] ?? 'unknown',
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Next round started',
                    'round' => $match->game_state['round_system']['current_round'] ?? null,
                ]);

            } finally {
                // 4. SIEMPRE liberar el lock (incluso si hubo excepción)
                $match->releaseRoundLock();
            }

        } catch (\Exception $e) {
            \Log::error('❌ [API] Error starting next round', [
                'match_id' => $match->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
