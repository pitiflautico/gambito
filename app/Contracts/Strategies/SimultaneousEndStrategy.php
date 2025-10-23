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
        // Obtener resultados de TODOS los jugadores
        $playerResults = $getAllPlayerResults($match);

        // Delegar a RoundManager para decidir
        // (RoundManager conoce la lógica de cuándo todos respondieron, etc.)
        $roundStatus = $roundManager->shouldEndSimultaneousRound(
            $playerResults,
            $this->config
        );

        // Agregar delay configurado
        if (!isset($roundStatus['delay_seconds'])) {
            $roundStatus['delay_seconds'] = $this->config['delay_seconds'];
        }

        return $roundStatus;
    }
}
