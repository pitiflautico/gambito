<?php

namespace Games\Trivia;

use App\Services\Modules\ScoringSystem\ScoreCalculatorInterface;

/**
 * Calculador de puntuación para Trivia.
 *
 * Puntos basados en:
 * - Dificultad de la pregunta (fácil: 80, media: 90, difícil: 100)
 * - Velocidad de respuesta (bonus hasta 50 puntos)
 */
class TriviaScoreCalculator implements ScoreCalculatorInterface
{
    protected array $config;

    public function __construct(array $config = [])
    {
        // Configuración por defecto desde config.json
        $this->config = array_merge([
            'base_points' => [
                'easy' => 80,
                'medium' => 90,
                'hard' => 100,
            ],
            'speed_bonus_max' => 50,
        ], $config);
    }

    /**
     * Calcular puntos para un evento.
     *
     * @param string $eventType Tipo de evento
     * @param array $context Contexto (difficulty, time_taken, time_limit, etc.)
     * @return int Puntos calculados
     */
    public function calculate(string $eventType, array $context): int
    {
        return match ($eventType) {
            'correct_answer' => $this->calculateCorrectAnswer($context),
            'speed_bonus' => $this->calculateSpeedBonus($context),
            'penalty' => -($context['points'] ?? 0),
            default => 0,
        };
    }

    /**
     * Calcular puntos por respuesta correcta.
     *
     * @param array $context ['difficulty' => string, 'time_taken' => float, 'time_limit' => int]
     * @return int
     */
    protected function calculateCorrectAnswer(array $context): int
    {
        // Si se pasan los puntos directamente, usarlos
        if (isset($context['points'])) {
            return $context['points'];
        }

        $difficulty = $context['difficulty'] ?? 'medium';
        $basePoints = $this->config['base_points'][$difficulty] ?? $this->config['base_points']['medium'];

        // Calcular bonus de velocidad si hay información de tiempo
        if (isset($context['time_taken'], $context['time_limit'])) {
            $speedBonus = $this->calculateSpeedBonus($context);
            return $basePoints + $speedBonus;
        }

        return $basePoints;
    }

    /**
     * Calcular bonus de velocidad.
     *
     * Bonus máximo si responde instantáneamente, 0 si usa todo el tiempo.
     *
     * @param array $context ['time_taken' => float, 'time_limit' => int]
     * @return int
     */
    protected function calculateSpeedBonus(array $context): int
    {
        $timeTaken = $context['time_taken'] ?? null;
        $timeLimit = $context['time_limit'] ?? null;

        if ($timeTaken === null || $timeLimit === null || $timeLimit <= 0) {
            return 0;
        }

        // Calcular porcentaje de tiempo usado
        $timeUsedPercent = min(1.0, $timeTaken / $timeLimit);

        // Bonus inversamente proporcional al tiempo usado
        // 0% tiempo = 100% bonus, 100% tiempo = 0% bonus
        $bonusPercent = 1.0 - $timeUsedPercent;

        return (int) round($bonusPercent * $this->config['speed_bonus_max']);
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
            'correct_answer',
            'speed_bonus',
            'penalty',
        ]);
    }
}


