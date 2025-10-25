<?php

namespace App\Contracts\Strategies;

use App\Models\GameMatch;
use App\Services\Modules\RoundSystem\RoundManager;

/**
 * Estrategia de finalización para modo SIMULTÁNEO.
 *
 * En modo simultáneo (Trivia, Quiz), todos los jugadores actúan al mismo tiempo.
 *
 * La ronda termina cuando:
 * 1. Primer jugador acierta (si first_to_win: true)
 * 2. Todos los jugadores respondieron
 * 3. Se cumple alguna condición custom del juego
 *
 * La decisión la toma RoundManager->shouldEndSimultaneousRound()
 * basándose en los resultados de todos los jugadores.
 */
class SimultaneousEndStrategy implements EndRoundStrategy
{
    /**
     * Configuración de la estrategia.
     *
     * @var array
     */
    protected array $config;

    /**
     * Constructor.
     *
     * @param array $config Configuración:
     *   - first_to_win: bool (default: true) - Terminar al primer acierto
     *   - delay_seconds: int (default: 5) - Delay antes de siguiente ronda
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'first_to_win' => true,
            'delay_seconds' => 5,
        ], $config);
    }

    /**
     * {@inheritDoc}
     */
    public function shouldEnd(
        GameMatch $match,
        array $actionResult,
        RoundManager $roundManager,
        callable $getAllPlayerResults
    ): array {
        // Verificar si la acción actual indicó force_end
        // El juego específico decide según sus reglas mediante este flag
        if ($actionResult['force_end'] ?? false) {
            return [
                'should_end' => true,
                'reason' => $actionResult['end_reason'] ?? 'game_rules',
                'winner_found' => false,
                'delay_seconds' => $this->config['delay_seconds'],
            ];
        }

        // No terminar - el juego debe usar force_end para indicar fin de ronda
        return [
            'should_end' => false,
            'reason' => 'waiting_for_game_decision',
            'winner_found' => false,
            'delay_seconds' => $this->config['delay_seconds'],
        ];
    }
}
