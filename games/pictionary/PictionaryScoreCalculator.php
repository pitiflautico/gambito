<?php

namespace Games\Pictionary;

use App\Services\Modules\ScoringSystem\ScoreCalculatorInterface;

/**
 * Pictionary Score Calculator
 *
 * Calcula puntos para:
 * - Jugadores que adivinan correctamente (base + speed bonus)
 * - Dibujante cuando alguien adivina
 */
class PictionaryScoreCalculator implements ScoreCalculatorInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'correct_guess_base' => 10,
            'drawer_success' => 5,
            'speed_bonus_max' => 5,
            'speed_thresholds' => [
                'fast' => 10,    // < 10s = +5 bonus
                'medium' => 20,  // 10-20s = +3 bonus
                'slow' => 30,    // 20-30s = +1 bonus
            ],
        ], $config);
    }

    /**
     * Calcular puntos según la acción.
     *
     * @param string $action 'correct_guess' | 'drawer_success'
     * @param array $context ['difficulty', 'time_taken', 'time_limit']
     * @return int Puntos calculados
     */
    public function calculate(string $action, array $context = []): int
    {
        return match($action) {
            'correct_guess' => $this->calculateCorrectGuess($context),
            'drawer_success' => $this->calculateDrawerSuccess($context),
            default => 0,
        };
    }

    /**
     * Calcular puntos para el jugador que adivinó correctamente.
     *
     * Fórmula: Base + Speed Bonus
     */
    protected function calculateCorrectGuess(array $context): int
    {
        $basePoints = $this->config['correct_guess_base'];

        // Speed bonus si hay timer
        if (isset($context['time_taken'], $context['time_limit'])) {
            $speedBonus = $this->calculateSpeedBonus($context);
            return $basePoints + $speedBonus;
        }

        return $basePoints;
    }

    /**
     * Calcular puntos para el dibujante cuando alguien adivina.
     *
     * TODO: Podría escalar según cuántos jugadores adivinaron
     */
    protected function calculateDrawerSuccess(array $context): int
    {
        return $this->config['drawer_success'];
    }

    /**
     * Calcular bonus por velocidad.
     *
     * Rangos:
     * - < 10s: +5 puntos (fast)
     * - 10-20s: +3 puntos (medium)
     * - 20-30s: +1 punto (slow)
     * - > 30s: +0 puntos
     */
    protected function calculateSpeedBonus(array $context): int
    {
        $timeTaken = $context['time_taken'];
        $thresholds = $this->config['speed_thresholds'];

        if ($timeTaken < $thresholds['fast']) {
            // Super rápido
            return $this->config['speed_bonus_max'];
        } elseif ($timeTaken < $thresholds['medium']) {
            // Rápido
            return (int) round($this->config['speed_bonus_max'] * 0.6);
        } elseif ($timeTaken < $thresholds['slow']) {
            // Lento pero dentro del tiempo
            return (int) round($this->config['speed_bonus_max'] * 0.2);
        }

        // Muy lento o sin tiempo
        return 0;
    }

    /**
     * Obtener configuración de puntuación.
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Verificar si un evento otorga puntos.
     *
     * @param string $eventType
     * @return bool
     */
    public function supportsEvent(string $eventType): bool
    {
        return in_array($eventType, [
            'correct_guess',
            'drawer_success',
        ]);
    }
}
