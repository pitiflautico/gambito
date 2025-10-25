<?php

namespace Games\Trivia;

use App\Contracts\BaseGameEngine;
use App\Models\GameMatch;
use App\Models\Player;
use App\Events\Game\RoundStartedEvent;
use Illuminate\Support\Facades\Log;

/**
 * Trivia Game Engine - Versión básica
 *
 * Solo implementa lo mínimo para verificar que el flujo híbrido funciona:
 * 1. initialize() - Guardar configuración mínima
 * 2. onGameStart() - Emitir evento para mostrar pantalla de inicio
 */
class TriviaEngine extends BaseGameEngine
{
    /**
     * Inicializar el juego - FASE 1 (LOBBY)
     *
     * Por ahora solo guardamos configuración mínima.
     */
    public function initialize(GameMatch $match): void
    {
        Log::info("[Trivia] Initializing - FASE 1", ['match_id' => $match->id]);

        // Guardar configuración mínima
        $match->game_state = [
            '_config' => [
                'game' => 'trivia',
                'initialized_at' => now()->toDateTimeString(),
            ],
            'phase' => 'waiting',
        ];

        $match->save();

        // Inicializar módulos automáticamente desde config.json
        $this->initializeModules($match);

        Log::info("[Trivia] Configuration saved", ['match_id' => $match->id]);
    }

    /**
     * Hook cuando el juego empieza - FASE 3 (POST-COUNTDOWN)
     *
     * BaseGameEngine ya resetó los módulos.
     * Solo emitimos un evento básico para mostrar "El juego ha empezado".
     */
    protected function onGameStart(GameMatch $match): void
    {
        Log::info("[Trivia] Game starting - FASE 3", ['match_id' => $match->id]);

        // Setear fase inicial
        $match->game_state = array_merge($match->game_state, [
            'phase' => 'playing',
            'started_at' => now()->toDateTimeString(),
        ]);

        $match->save();

        // Emitir evento genérico RoundStartedEvent
        event(new RoundStartedEvent(
            match: $match,
            currentRound: 1,
            totalRounds: 1,
            phase: 'playing',
            timing: null
        ));

        Log::info("[Trivia] RoundStartedEvent emitted", [
            'match_id' => $match->id,
            'room_code' => $match->room->code
        ]);
    }

    /**
     * Procesar acción de ronda (abstracto de BaseGameEngine).
     * Por ahora no hace nada.
     */
    protected function processRoundAction(GameMatch $match, Player $player, array $data): array
    {
        return ['success' => false, 'message' => 'No implementado aún'];
    }

    /**
     * Iniciar nueva ronda (abstracto de BaseGameEngine).
     * Por ahora no hace nada.
     */
    protected function startNewRound(GameMatch $match): void
    {
        Log::info("[Trivia] startNewRound called (not implemented yet)", ['match_id' => $match->id]);
    }

    /**
     * Finalizar ronda actual (abstracto de BaseGameEngine).
     * Por ahora no hace nada.
     */
    protected function endCurrentRound(GameMatch $match): void
    {
        Log::info("[Trivia] endCurrentRound called (not implemented yet)", ['match_id' => $match->id]);
    }

    /**
     * Obtener resultados de todos los jugadores (abstracto de BaseGameEngine).
     * Por ahora retorna array vacío.
     */
    protected function getAllPlayerResults(GameMatch $match): array
    {
        return [];
    }

    /**
     * Procesar acción de un jugador.
     * Por ahora no hace nada.
     */
    public function processAction(GameMatch $match, Player $player, string $action, array $data): array
    {
        return ['success' => false, 'message' => 'No implementado aún'];
    }

    /**
     * Verificar condición de victoria.
     * Por ahora siempre retorna null (no hay ganador).
     */
    public function checkWinCondition(GameMatch $match): ?Player
    {
        return null;
    }

    /**
     * Obtener estado del juego para un jugador.
     */
    public function getGameStateForPlayer(GameMatch $match, Player $player): array
    {
        return [
            'phase' => $match->game_state['phase'] ?? 'unknown',
            'message' => 'El juego ha empezado',
        ];
    }

    /**
     * Manejar desconexión de jugador.
     */
    public function handlePlayerDisconnect(GameMatch $match, Player $player): void
    {
        Log::info("[Trivia] Player disconnected", [
            'match_id' => $match->id,
            'player_id' => $player->id
        ]);
    }

    /**
     * Manejar reconexión de jugador.
     */
    public function handlePlayerReconnect(GameMatch $match, Player $player): void
    {
        Log::info("[Trivia] Player reconnected", [
            'match_id' => $match->id,
            'player_id' => $player->id
        ]);
    }

    /**
     * Finalizar el juego.
     */
    public function finalize(GameMatch $match): array
    {
        Log::info("[Trivia] Finalizing game", ['match_id' => $match->id]);

        return [
            'winner' => null,
            'ranking' => [],
            'statistics' => [],
        ];
    }
}
