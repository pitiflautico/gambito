<?php

namespace Games\Mockup;

use App\Contracts\BaseGameEngine;
use App\Models\GameMatch;
use App\Models\Player;

/**
 * MockupEngine - Motor de juego mockup para testing
 *
 * Este engine cumple TODAS las convenciones de arquitectura y sirve como:
 * - Modelo de referencia para implementar otros juegos
 * - Engine para testing sin lógica compleja
 * - Validación de eventos y estados del sistema
 *
 * IMPORTANTE: Este engine NO implementa lógica de juego real,
 * solo emite eventos y cambia estados según las convenciones.
 */
class MockupEngine extends BaseGameEngine
{
    /**
     * Inicializar el motor del juego.
     *
     * Se llama UNA VEZ al crear el match.
     * Configura el estado inicial del juego.
     */
    public function initialize(GameMatch $match): void
    {
        \Log::info("[MockupEngine] Initializing game", [
            'match_id' => $match->id,
            'players_count' => $match->players()->count(),
        ]);

        // Estado inicial del juego mockup
        $this->setState($match, [
            'phase' => 'initialized',
            'turn_count' => 0,
            'scores' => [],
        ]);
    }

    /**
     * Iniciar el juego.
     *
     * Se llama cuando el juego realmente empieza (después del countdown).
     * Resetea módulos y emite el evento de inicio.
     */
    public function startGame(GameMatch $match): void
    {
        \Log::info("[MockupEngine] Starting game", [
            'match_id' => $match->id,
        ]);

        // Emitir evento "starting" (cuenta regresiva)
        $this->emitGenericEvent($match, 'starting', [
            'countdown_seconds' => 3,
            'message' => 'El juego comenzará en 3 segundos...',
        ]);

        // Inicializar scores para todos los jugadores
        $scores = [];
        foreach ($match->players as $player) {
            $scores[$player->id] = 0;
        }

        // Actualizar estado
        $this->setState($match, [
            'phase' => 'playing',
            'turn_count' => 0,
            'current_turn' => 1,
            'scores' => $scores,
        ]);

        // Emitir evento genérico de inicio
        $this->emitGenericEvent($match, 'started', [
            'message' => '¡Juego iniciado!',
            'players_count' => count($scores),
        ]);
    }

    /**
     * Procesar el turno de un jugador.
     */
    public function playTurn(GameMatch $match, Player $player, array $action): array
    {
        \Log::info("[MockupEngine] Player turn", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'action' => $action,
        ]);

        $state = $this->getState($match);

        // Incrementar score del jugador (acción mockup)
        $state['scores'][$player->id] = ($state['scores'][$player->id] ?? 0) + 1;
        $state['turn_count']++;

        $this->setState($match, $state);

        // Emitir evento de turno jugado
        $this->emitGenericEvent($match, 'turn.played', [
            'player_id' => $player->id,
            'player_name' => $player->name,
            'new_score' => $state['scores'][$player->id],
            'turn_count' => $state['turn_count'],
        ]);

        return [
            'success' => true,
            'score' => $state['scores'][$player->id],
        ];
    }

    /**
     * Finalizar el juego.
     */
    public function endGame(GameMatch $match, ?Player $winner = null): void
    {
        \Log::info("[MockupEngine] Ending game", [
            'match_id' => $match->id,
            'winner_id' => $winner?->id,
        ]);

        $state = $this->getState($match);
        $state['phase'] = 'finished';

        $this->setState($match, $state);

        // Emitir evento de fin de juego
        $this->emitGenericEvent($match, 'finished', [
            'winner_id' => $winner?->id,
            'winner_name' => $winner?->name,
            'final_scores' => $state['scores'],
            'total_turns' => $state['turn_count'],
        ]);
    }

    /**
     * Validar si una acción es válida.
     */
    public function validateAction(GameMatch $match, Player $player, array $action): bool
    {
        // En el mockup, todas las acciones son válidas
        return true;
    }

    /**
     * Obtener el estado del juego para un jugador específico.
     */
    public function getPlayerState(GameMatch $match, Player $player): array
    {
        $state = $this->getState($match);

        return [
            'phase' => $state['phase'] ?? 'initialized',
            'your_score' => $state['scores'][$player->id] ?? 0,
            'all_scores' => $state['scores'] ?? [],
            'turn_count' => $state['turn_count'] ?? 0,
        ];
    }
}
