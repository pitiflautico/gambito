<?php

namespace Games\Trivia;

use App\Services\Modules\ScoringSystem\ScoreCalculatorInterface;

/**
 * Trivia Score Calculator
 *
 * Calcula puntos para respuestas correctas con bonus por velocidad.
 *
 * Sistema de puntos (lee desde config.json):
 * - Puntos base según dificultad:
 *   - Easy: 80 puntos
 *   - Medium: 90 puntos
 *   - Hard: 100 puntos
 * - Bonus por velocidad: Hasta 50 puntos adicionales
 *   - Responder en primeros 25% del tiempo: +50 puntos
 *   - Responder en primeros 50% del tiempo: +30 puntos
 *   - Responder en primeros 75% del tiempo: +10 puntos
 *   - Responder después del 75%: +0 puntos
 *
 * Ejemplo con pregunta HARD y tiempo límite de 10 segundos:
 * - 0-2.5s: 100 + 50 = 150 puntos
 * - 2.5-5s: 100 + 30 = 130 puntos
 * - 5-7.5s: 100 + 10 = 110 puntos
 * - 7.5-10s: 100 + 0 = 100 puntos
 */
class TriviaScoreCalculator implements ScoreCalculatorInterface
{
    /**
     * Eventos soportados por este calculador.
     */
    private const SUPPORTED_EVENTS = [
        'correct_answer',
    ];

    /**
     * Puntos base por dificultad (se lee desde config.json, estos son defaults).
     */
    private const BASE_POINTS = [
        'easy' => 80,
        'medium' => 90,
        'hard' => 100,
    ];

    /**
     * Bonus máximo por velocidad.
     */
    private const MAX_SPEED_BONUS = 50;

    /**
     * Umbrales de tiempo para bonus (en porcentaje del tiempo límite).
     */
    private const SPEED_THRESHOLDS = [
        25 => 50,  // Primeros 25% del tiempo: +50 puntos
        50 => 30,  // Primeros 50% del tiempo: +30 puntos
        75 => 10,  // Primeros 75% del tiempo: +10 puntos
    ];

    /**
     * Calcular puntos para un evento.
     */
    public function calculate(string $eventType, array $context): int
    {
        if (!$this->supportsEvent($eventType)) {
            throw new \InvalidArgumentException("Evento no soportado: {$eventType}");
        }

        return match ($eventType) {
            'correct_answer' => $this->calculateCorrectAnswerPoints($context),
            default => 0,
        };
    }

    /**
     * Verificar si el calculador soporta un evento.
     */
    public function supportsEvent(string $eventType): bool
    {
        return in_array($eventType, self::SUPPORTED_EVENTS);
    }

    /**
     * Obtener configuración del calculador.
     */
    public function getConfig(): array
    {
        return [
            'base_points' => self::BASE_POINTS,
            'max_speed_bonus' => self::MAX_SPEED_BONUS,
            'speed_thresholds' => self::SPEED_THRESHOLDS,
            'supported_events' => self::SUPPORTED_EVENTS,
        ];
    }

    // ========================================================================
    // MÉTODOS PRIVADOS
    // ========================================================================

    /**
     * Calcular puntos por respuesta correcta.
     */
    private function calculateCorrectAnswerPoints(array $context): int
    {
        $secondsElapsed = $context['seconds_elapsed'] ?? $context['time_elapsed'] ?? null;
        $timeLimit = $context['time_limit'] ?? null;
        $difficulty = $context['difficulty'] ?? 'medium';

        if ($secondsElapsed === null || $timeLimit === null) {
            throw new \InvalidArgumentException('Se requieren seconds_elapsed/time_elapsed y time_limit');
        }

        // Obtener puntos base según dificultad
        $basePoints = self::BASE_POINTS[$difficulty] ?? self::BASE_POINTS['medium'];

        // Calcular bonus por velocidad
        $speedBonus = $this->calculateSpeedBonus($secondsElapsed, $timeLimit);

        return $basePoints + $speedBonus;
    }

    /**
     * Calcular bonus por velocidad de respuesta.
     */
    private function calculateSpeedBonus(float $secondsElapsed, float $timeLimit): int
    {
        // Calcular porcentaje del tiempo usado
        $percentageUsed = ($secondsElapsed / $timeLimit) * 100;

        // Determinar bonus según umbrales
        foreach (self::SPEED_THRESHOLDS as $threshold => $bonus) {
            if ($percentageUsed <= $threshold) {
                return $bonus;
            }
        }

        // No hay bonus si respondió muy tarde
        return 0;
    }
}
