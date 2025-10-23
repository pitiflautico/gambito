<?php

namespace App\Contracts\Strategies;

use App\Models\GameMatch;
use App\Services\Modules\RoundSystem\RoundManager;

/**
 * Estrategia de finalización para Pictionary basada en FASES.
 *
 * Pictionary tiene 3 fases distintas:
 * 1. PLAYING - Dibujante dibuja, adivinadores intentan adivinar
 * 2. SCORING - Mostrando resultados de la ronda
 * 3. RESULTS - Resultados finales del juego
 *
 * La lógica de finalización cambia según la fase:
 *
 * FASE PLAYING:
 * - Termina cuando el dibujante confirma una respuesta correcta
 * - Termina cuando todos los adivinadores han fallado
 * - Termina cuando se acaba el tiempo
 *
 * FASE SCORING:
 * - NO termina automáticamente (Frontend controla timing)
 * - Frontend llama a advancePhase() después de mostrar resultados
 *
 * FASE RESULTS:
 * - Juego terminado, no hay siguiente ronda
 *
 * Esta estrategia demuestra cómo un juego puede tener diferentes
 * lógicas de finalización según su estado interno.
 */
class PictionaryPhaseStrategy implements EndRoundStrategy
{
    /**
     * {@inheritDoc}
     */
    public function shouldEnd(
        GameMatch $match,
        array $actionResult,
        RoundManager $roundManager,
        callable $getAllPlayerResults
    ): array {
        $gameState = $match->game_state;
        $currentPhase = $gameState['phase'] ?? 'playing';

        return match ($currentPhase) {
            'playing' => $this->handlePlayingPhase($actionResult),
            'scoring' => $this->handleScoringPhase($actionResult),
            'results' => $this->handleResultsPhase(),
            default => [
                'should_end' => false,
                'reason' => 'unknown_phase',
                'delay_seconds' => 0,
            ],
        };
    }

    /**
     * Manejar fase PLAYING.
     *
     * En esta fase:
     * - El dibujante dibuja
     * - Los adivinadores intentan adivinar
     * - El turno termina cuando el dibujante confirma una respuesta
     *
     * @param array $actionResult
     * @return array
     */
    private function handlePlayingPhase(array $actionResult): array
    {
        // El juego decide cuándo terminar vía 'should_end_turn'
        // Similar a SequentialEndStrategy
        $shouldEnd = $actionResult['should_end_turn'] ?? false;

        if ($shouldEnd) {
            // Razón específica del juego
            $reason = $actionResult['end_reason'] ?? 'turn_completed';

            return [
                'should_end' => true,
                'reason' => $reason,
                'delay_seconds' => 0,  // Sin delay, cambio de fase inmediato
            ];
        }

        return [
            'should_end' => false,
            'reason' => 'turn_ongoing',
            'delay_seconds' => 0,
        ];
    }

    /**
     * Manejar fase SCORING.
     *
     * En esta fase:
     * - Se muestran los resultados de la ronda
     * - Frontend controla el timing (3 segundos)
     * - Frontend llama a advancePhase() para continuar
     *
     * Por lo tanto, NO debemos terminar automáticamente aquí.
     *
     * @param array $actionResult
     * @return array
     */
    private function handleScoringPhase(array $actionResult): array
    {
        // En fase scoring, NO terminamos automáticamente
        // El frontend detecta la fase y llama a advancePhase()
        return [
            'should_end' => false,
            'reason' => 'awaiting_frontend',
            'delay_seconds' => 0,
        ];
    }

    /**
     * Manejar fase RESULTS.
     *
     * El juego ha terminado, no hay siguiente ronda.
     *
     * @return array
     */
    private function handleResultsPhase(): array
    {
        return [
            'should_end' => false,
            'reason' => 'game_finished',
            'delay_seconds' => 0,
        ];
    }
}
