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

            // 1. Validar que el juego está en fase correcta
            $currentPhase = $match->game_state['phase'] ?? null;

            if ($currentPhase !== 'results') {
                \Log::warning('⚠️  [API] Invalid phase for starting next round', [
                    'match_id' => $match->id,
                    'expected_phase' => 'results',
                    'actual_phase' => $currentPhase,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Game is not in results phase',
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
}
