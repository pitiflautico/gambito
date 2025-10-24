<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\Core\GameRegistry;
use Illuminate\Http\Request;

class GameController extends Controller
{
    /**
     * Game registry service.
     */
    protected GameRegistry $registry;

    public function __construct(GameRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Listar todos los juegos disponibles.
     *
     * Muestra una página con todos los juegos activos.
     */
    public function index()
    {
        $games = $this->registry->getActiveGames();

        return view('games.index', compact('games'));
    }

    /**
     * Mostrar detalles de un juego específico.
     *
     * @param string $slug Slug del juego
     */
    public function show(string $slug)
    {
        $game = Game::where('slug', $slug)->where('is_active', true)->firstOrFail();

        return view('games.show', compact('game'));
    }

    /**
     * API: Obtener lista de juegos disponibles en JSON.
     */
    public function apiIndex()
    {
        $games = $this->registry->getActiveGames();

        return response()->json([
            'success' => true,
            'data' => $games->map(function ($game) {
                return [
                    'id' => $game->id,
                    'name' => $game->name,
                    'slug' => $game->slug,
                    'description' => $game->description,
                    'min_players' => $game->min_players,
                    'max_players' => $game->max_players,
                    'estimated_duration' => $game->estimated_duration,
                    'is_premium' => $game->is_premium,
                ];
            }),
        ]);
    }

    /**
     * API: Obtener configuración completa de un juego.
     *
     * @param string $slug Slug del juego
     */
    public function apiShow(string $slug)
    {
        $game = Game::where('slug', $slug)->where('is_active', true)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $game->id,
                'name' => $game->name,
                'slug' => $game->slug,
                'description' => $game->description,
                'config' => $game->config,
                'capabilities' => $game->capabilities,
                'min_players' => $game->min_players,
                'max_players' => $game->max_players,
                'estimated_duration' => $game->estimated_duration,
                'is_premium' => $game->is_premium,
            ],
        ]);
    }

    /**
     * API: Iniciar juego después del countdown de inicio (con protección contra race conditions).
     *
     * Este endpoint es llamado por el frontend cuando el countdown de GameStartedEvent
     * termina. Usa un lock mechanism para prevenir que múltiples clientes inicien
     * el juego simultáneamente.
     *
     * Race Condition Protection:
     * - Solo el primer cliente en adquirir el lock iniciará el juego
     * - Otros clientes recibirán 409 Conflict
     * - Todos los clientes se sincronizarán con los eventos del juego (ej: QuestionStartedEvent)
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
     * Otros clientes recibirán 409 Conflict
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

            // 1. Validar que el juego está en fase correcta
            $currentPhase = $match->game_state['phase'] ?? null;

            // Aceptar tanto "starting" (primer round) como "results" (siguientes rounds)
            if ($currentPhase !== 'results' && $currentPhase !== 'starting') {
                \Log::warning('⚠️  [API] Invalid phase for starting next round', [
                    'match_id' => $match->id,
                    'expected_phases' => ['starting', 'results'],
                    'actual_phase' => $currentPhase,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Game is not in valid phase (expected: starting or results)',
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

                // Obtener el engine del juego
                $game = $match->room->game;
                $engineClass = $game->getEngineClass();

                if (!$engineClass || !class_exists($engineClass)) {
                    throw new \RuntimeException("Game engine not found for game: {$game->slug}");
                }

                $engine = app($engineClass);

                // Avanzar a la siguiente ronda (advancePhase llama a startNewRound)
                $engine->advancePhase($match);

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

    /**
     * API: Notificar que el tiempo del turno expiró (con protección contra race conditions).
     *
     * Este endpoint es llamado por el frontend cuando el countdown del timer llega a 0.
     * Usa un lock mechanism para prevenir que múltiples clientes procesen el timeout simultáneamente.
     *
     * Flujo:
     * 1. Frontend detecta que el timer llegó a 0
     * 2. Frontend llama a este endpoint
     * 3. Backend verifica que el tiempo realmente expiró (consultando TurnManager)
     * 4. Backend emite TurnTimeoutEvent (evento interno, no broadcast)
     * 5. Los engines específicos escuchan TurnTimeoutEvent y ejecutan su lógica
     *
     * Ejemplo en Trivia:
     * - TriviaEngine escucha TurnTimeoutEvent
     * - Ejecuta endCurrentRound() (asigna 0 puntos a quien no respondió)
     * - Emite RoundEndedEvent (este sí se broadcast al frontend)
     *
     * Race Condition Protection:
     * - Solo el primer cliente en adquirir el lock procesará el timeout
     * - Otros clientes recibirán 409 Conflict
     * - Todos los clientes se sincronizarán con los eventos resultantes (ej: RoundEndedEvent)
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\GameMatch $match
     */
    public function turnTimeout(Request $request, \App\Models\GameMatch $match)
    {
        try {
            \Log::info('📥 [API] turnTimeout request received', [
                'match_id' => $match->id,
                'room_code' => $match->room->code,
                'current_phase' => $match->game_state['phase'] ?? 'unknown',
            ]);

            // 1. Obtener el TurnManager del game_state
            $gameState = $match->game_state;

            if (!isset($gameState['turn_system'])) {
                \Log::warning('⚠️  [API] Turn system not enabled for this match', [
                    'match_id' => $match->id,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Turn system not enabled',
                ], 400);
            }

            $turnManager = \App\Services\Modules\TurnSystem\TurnManager::fromArray($gameState['turn_system']);
            $turnManager->setTimerService(\App\Services\Modules\TimerSystem\TimerService::fromArray($gameState));

            // 2. Verificar si el tiempo realmente expiró
            if (!$turnManager->isTimeExpired()) {
                \Log::info('⏸️  [API] Turn time has not expired yet', [
                    'match_id' => $match->id,
                    'remaining_time' => $turnManager->getRemainingTime(),
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Turn time has not expired yet',
                    'remaining_time' => $turnManager->getRemainingTime(),
                ], 400);
            }

            // 3. Intentar adquirir lock (solo el primer cliente lo consigue)
            if (!$match->acquireRoundLock()) {
                \Log::info('⏸️  [API] Lock already held, another client is processing timeout', [
                    'match_id' => $match->id,
                ]);

                return response()->json([
                    'success' => true,
                    'already_processing' => true,
                    'message' => 'Another client is processing the timeout, you will receive events shortly',
                ], 200);
            }

            // 4. Lock adquirido - llamar al engine directamente
            try {
                \Log::info('🔒 [API] Lock acquired, calling engine->onTurnTimeout()', [
                    'match_id' => $match->id,
                    'turn_index' => $turnManager->getCurrentTurnIndex(),
                ]);

                // Obtener engine del juego
                $game = $match->room->game;
                $engineClass = $game->getEngineClass();
                $engine = app($engineClass);

                // Llamar al engine directamente (sin eventos)
                $engine->onTurnTimeout($match);

                \Log::info('✅ [API] Engine->onTurnTimeout() executed successfully', [
                    'match_id' => $match->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Timeout processed',
                ]);

            } finally {
                // 5. SIEMPRE liberar el lock (incluso si hubo excepción)
                $match->releaseRoundLock();
            }

        } catch (\Exception $e) {
            \Log::error('❌ [API] Error processing turn timeout', [
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
