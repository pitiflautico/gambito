<?php

namespace App\Contracts\Strategies;

use App\Models\GameMatch;
use App\Services\Modules\RoundSystem\RoundManager;

/**
 * Estrategia para determinar cuándo finalizar una ronda/turno.
 *
 * Cada modo de juego (simultaneous, sequential, free, etc.)
 * puede tener su propia lógica de finalización.
 *
 * Esto permite:
 * - Extensibilidad: Agregar nuevos modos sin modificar BaseGameEngine
 * - Flexibilidad: Juegos pueden sobrescribir estrategias
 * - Testabilidad: Estrategias aisladas y fáciles de testear
 * - Múltiples modos: Juegos pueden cambiar de estrategia por fase
 */
interface EndRoundStrategy
{
    /**
     * Determinar si la ronda/turno debe finalizar.
     *
     * @param GameMatch $match Match actual del juego
     * @param array $actionResult Resultado de processRoundAction() del Engine
     * @param RoundManager $roundManager Gestor de rondas y turnos
     * @param callable $getAllPlayerResults Callback para obtener resultados de todos los jugadores
     *                                      Firma: function(GameMatch $match): array
     * @return array [
     *   'should_end' => bool,
     *   'reason' => string,
     *   'delay_seconds' => int
     * ]
     */
    public function shouldEnd(
        GameMatch $match,
        array $actionResult,
        RoundManager $roundManager,
        callable $getAllPlayerResults
    ): array;
}
