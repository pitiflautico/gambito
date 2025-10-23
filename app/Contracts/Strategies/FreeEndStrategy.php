<?php

namespace App\Contracts\Strategies;

use App\Models\GameMatch;
use App\Services\Modules\RoundSystem\RoundManager;

/**
 * Estrategia de finalización para modo LIBRE (FREE).
 *
 * En modo libre, no hay turnos fijos. Los jugadores pueden actuar
 * cuando quieran, sin restricciones de orden.
 *
 * Casos de uso:
 * - Juegos de mesa libres
 * - Juegos en tiempo real
 * - Juegos asíncronos
 *
 * La ronda termina cuando:
 * 1. El juego específico lo decide (retorna 'should_end_round' => true)
 * 2. Se alcanza una condición de victoria
 * 3. Timeout global
 *
 * Similar a Sequential, pero el flag es 'should_end_round' en lugar de 'should_end_turn'
 * porque no hay concepto de "turno" en modo libre.
 */
class FreeEndStrategy implements EndRoundStrategy
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
     *   - delay_seconds: int (default: 3) - Delay antes de siguiente ronda
     *   - allow_simultaneous: bool (default: true) - Permitir acciones simultáneas
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'delay_seconds' => 3,
            'allow_simultaneous' => true,
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
        // En modo libre, el JUEGO decide cuándo terminar la RONDA
        // (no hay concepto de "turno")
        $shouldEnd = $actionResult['should_end_round'] ?? false;

        // El juego puede especificar un delay custom
        $delaySeconds = $actionResult['delay_seconds'] ?? $this->config['delay_seconds'];

        // Determinar razón
        $reason = 'round_ongoing';
        if ($shouldEnd) {
            $reason = $actionResult['end_reason'] ?? 'round_completed';
        }

        return [
            'should_end' => $shouldEnd,
            'reason' => $reason,
            'delay_seconds' => $delaySeconds,
        ];
    }
}
