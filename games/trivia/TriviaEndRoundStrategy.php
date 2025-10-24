<?php

namespace Games\Trivia;

use App\Contracts\Strategies\EndRoundStrategy;
use App\Models\GameMatch;

/**
 * Estrategia de finalización de ronda para Trivia
 *
 * REGLAS DE FINALIZACIÓN:
 * 1. Si hay un ganador (primera respuesta correcta) → Terminar inmediatamente
 * 2. Si todos los jugadores están bloqueados (todos fallaron) → Terminar (nadie gana)
 * 3. Si aún hay jugadores sin responder y no bloqueados → Continuar esperando
 *
 * NOTA: El timer se gestiona externamente con TimerService y llama a handleTimeExpired()
 *       cuando se acaba el tiempo, que fuerza el fin de ronda.
 */
class TriviaEndRoundStrategy implements EndRoundStrategy
{
    /**
     * Determinar si la ronda debe terminar.
     *
     * @param GameMatch $match
     * @param array $playerResults Resultados actuales de los jugadores
     * @return array ['should_end' => bool, 'reason' => string, 'winner_found' => bool, ...]
     */
    public function shouldEnd(GameMatch $match, array $playerResults): array
    {
        $gameState = $match->game_state;
        $roundState = $gameState['round_state'] ?? [];

        // =====================================================================
        // REGLA 1: ¿Ya hay un ganador? (primera respuesta correcta)
        // =====================================================================
        if (isset($roundState['winner_id'])) {
            \Log::info('[TriviaStrategy] Round should end: winner found', [
                'match_id' => $match->id,
                'winner_id' => $roundState['winner_id']
            ]);

            return [
                'should_end' => true,
                'reason' => 'player_won',
                'winner_found' => true,
                'winner_id' => $roundState['winner_id']
            ];
        }

        // =====================================================================
        // REGLA 2: ¿Todos los jugadores están bloqueados? (todos fallaron)
        // =====================================================================
        $totalPlayers = $match->players->count();
        $blockedPlayers = count($roundState['blocked_players'] ?? []);

        if ($blockedPlayers >= $totalPlayers) {
            \Log::info('[TriviaStrategy] Round should end: all players blocked', [
                'match_id' => $match->id,
                'total_players' => $totalPlayers,
                'blocked_players' => $blockedPlayers
            ]);

            return [
                'should_end' => true,
                'reason' => 'all_players_blocked',
                'winner_found' => false
            ];
        }

        // =====================================================================
        // REGLA 3: Continuar esperando (hay jugadores sin responder)
        // =====================================================================
        $answeredPlayers = count($roundState['answers'] ?? []);

        \Log::debug('[TriviaStrategy] Round continues: waiting for players', [
            'match_id' => $match->id,
            'total_players' => $totalPlayers,
            'answered' => $answeredPlayers,
            'blocked' => $blockedPlayers,
            'waiting_for' => $totalPlayers - $answeredPlayers
        ]);

        return [
            'should_end' => false,
            'reason' => 'waiting_for_players',
            'winner_found' => false,
            'stats' => [
                'total_players' => $totalPlayers,
                'answered' => $answeredPlayers,
                'blocked' => $blockedPlayers,
                'waiting_for' => $totalPlayers - $answeredPlayers
            ]
        ];
    }

    /**
     * Obtener configuración de la estrategia.
     *
     * @return array
     */
    public function getConfig(): array
    {
        return [
            'name' => 'TriviaEndRoundStrategy',
            'description' => 'Termina cuando hay ganador o todos están bloqueados',
            'rules' => [
                'winner_found' => 'Primera respuesta correcta termina la ronda',
                'all_blocked' => 'Todos los jugadores bloqueados termina la ronda',
                'timer_expired' => 'Timer gestionado externamente por TimerService'
            ]
        ];
    }
}
