<?php

namespace App\Contracts\Strategies;

use App\Models\GameMatch;
use App\Services\Modules\RoundSystem\RoundManager;

/**
 * Estrategia de finalización para modo SECUENCIAL.
 *
 * En modo secuencial (Pictionary, UNO), los jugadores actúan por turnos.
 *
 * El turno termina cuando:
 * 1. El juego específico decide (retorna 'should_end_turn' => true)
 * 2. Se cumple el timeout del turno
 * 3. El jugador completa su acción
 *
 * La decisión la toma el Engine específico del juego,
 * esta estrategia solo lee el flag 'should_end_turn' del resultado.
 */
class SequentialEndStrategy implements EndRoundStrategy
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
     *   - delay_seconds: int (default: 3) - Delay antes de siguiente turno
     *   - auto_advance: bool (default: false) - Avanzar automáticamente si no hay acción
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'delay_seconds' => 3,
            'auto_advance' => false,
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
        // En modo secuencial, el JUEGO decide cuándo terminar
        // Lee el flag 'force_end' o 'should_end_turn' (legacy) del resultado
        $shouldEnd = $actionResult['force_end'] ?? $actionResult['should_end_turn'] ?? false;

        // El juego puede especificar un delay custom
        $delaySeconds = $actionResult['delay_seconds'] ?? $this->config['delay_seconds'];

        // Determinar razón
        $reason = 'turn_ongoing';
        if ($shouldEnd) {
            $reason = $actionResult['end_reason'] ?? 'turn_completed';
        }

        return [
            'should_end' => $shouldEnd,
            'reason' => $reason,
            'delay_seconds' => $delaySeconds,
        ];
    }
}
