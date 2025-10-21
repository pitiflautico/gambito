<?php

namespace Games\Pictionary;

use App\Services\Modules\ScoringSystem\ScoreCalculatorInterface;

/**
 * Calculador de puntuación específico para Pictionary.
 *
 * Sistema de puntuación basado en velocidad de respuesta:
 * - El jugador que adivina gana puntos según qué tan rápido respondió
 * - El dibujante gana puntos bonus si alguien acierta (menos que el adivinador)
 * - Se usan umbrales basados en % del tiempo máximo del turno
 *
 * Eventos soportados:
 * - 'correct_answer': Adivinador acertó (requiere: seconds_elapsed, turn_duration)
 * - 'drawer_bonus': Bonus para dibujante (requiere: seconds_elapsed, turn_duration)
 */
class PictionaryScoreCalculator implements ScoreCalculatorInterface
{
    /**
     * Configuración de puntos por velocidad (adivinador).
     *
     * @var array
     */
    protected array $guesserPoints = [
        'fast' => 150,    // 0-33% del tiempo
        'normal' => 100,  // 34-67% del tiempo
        'slow' => 50,     // 68-100% del tiempo
        'timeout' => 0,   // >100% del tiempo
    ];

    /**
     * Configuración de puntos por velocidad (dibujante).
     *
     * @var array
     */
    protected array $drawerPoints = [
        'fast' => 75,     // 0-33% del tiempo
        'normal' => 50,   // 34-67% del tiempo
        'slow' => 25,     // 68-100% del tiempo
        'timeout' => 0,   // >100% del tiempo
    ];

    /**
     * Umbrales de tiempo (porcentajes).
     *
     * @var array
     */
    protected array $thresholds = [
        'fast' => 0.33,   // 33% del tiempo máximo
        'normal' => 0.67, // 67% del tiempo máximo
    ];

    /**
     * {@inheritdoc}
     */
    public function calculate(string $eventType, array $context): int
    {
        return match ($eventType) {
            'correct_answer' => $this->calculateGuesserPoints($context),
            'drawer_bonus' => $this->calculateDrawerPoints($context),
            default => throw new \InvalidArgumentException("Evento '{$eventType}' no soportado por PictionaryScoreCalculator"),
        };
    }

    /**
     * Calcular puntos para el adivinador según tiempo transcurrido.
     *
     * @param array $context ['seconds_elapsed' => int, 'turn_duration' => int]
     * @return int Puntos calculados
     */
    protected function calculateGuesserPoints(array $context): int
    {
        $this->validateContext($context, ['seconds_elapsed', 'turn_duration']);

        $secondsElapsed = $context['seconds_elapsed'];
        $maxTime = $context['turn_duration'];

        $speed = $this->getSpeedCategory($secondsElapsed, $maxTime);

        return $this->guesserPoints[$speed];
    }

    /**
     * Calcular puntos bonus para el dibujante.
     *
     * @param array $context ['seconds_elapsed' => int, 'turn_duration' => int]
     * @return int Puntos calculados
     */
    protected function calculateDrawerPoints(array $context): int
    {
        $this->validateContext($context, ['seconds_elapsed', 'turn_duration']);

        $secondsElapsed = $context['seconds_elapsed'];
        $maxTime = $context['turn_duration'];

        $speed = $this->getSpeedCategory($secondsElapsed, $maxTime);

        return $this->drawerPoints[$speed];
    }

    /**
     * Determinar categoría de velocidad según tiempo transcurrido.
     *
     * @param int $secondsElapsed Segundos transcurridos
     * @param int $maxTime Tiempo máximo del turno
     * @return string 'fast' | 'normal' | 'slow' | 'timeout'
     */
    protected function getSpeedCategory(int $secondsElapsed, int $maxTime): string
    {
        if ($secondsElapsed > $maxTime) {
            return 'timeout';
        }

        $fastThreshold = $maxTime * $this->thresholds['fast'];    // 33%
        $normalThreshold = $maxTime * $this->thresholds['normal']; // 67%

        if ($secondsElapsed <= $fastThreshold) {
            return 'fast';
        } elseif ($secondsElapsed <= $normalThreshold) {
            return 'normal';
        } else {
            return 'slow';
        }
    }

    /**
     * Validar que el contexto tiene los campos requeridos.
     *
     * @param array $context Contexto del evento
     * @param array $requiredFields Campos requeridos
     * @return void
     * @throws \InvalidArgumentException Si falta algún campo
     */
    protected function validateContext(array $context, array $requiredFields): void
    {
        foreach ($requiredFields as $field) {
            if (!isset($context[$field])) {
                throw new \InvalidArgumentException("El contexto requiere el campo '{$field}'");
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(): array
    {
        return [
            'guesser_points' => $this->guesserPoints,
            'drawer_points' => $this->drawerPoints,
            'thresholds' => $this->thresholds,
            'supported_events' => ['correct_answer', 'drawer_bonus'],
            'scoring_method' => 'time_based',
            'description' => 'Puntuación basada en velocidad de respuesta con umbrales de 33% y 67%',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function supportsEvent(string $eventType): bool
    {
        return in_array($eventType, ['correct_answer', 'drawer_bonus']);
    }

    /**
     * Personalizar puntos del adivinador (opcional, para configuración).
     *
     * @param array $points ['fast' => int, 'normal' => int, 'slow' => int, 'timeout' => int]
     * @return self
     */
    public function setGuesserPoints(array $points): self
    {
        $this->guesserPoints = array_merge($this->guesserPoints, $points);
        return $this;
    }

    /**
     * Personalizar puntos del dibujante (opcional, para configuración).
     *
     * @param array $points ['fast' => int, 'normal' => int, 'slow' => int, 'timeout' => int]
     * @return self
     */
    public function setDrawerPoints(array $points): self
    {
        $this->drawerPoints = array_merge($this->drawerPoints, $points);
        return $this;
    }

    /**
     * Personalizar umbrales de velocidad (opcional, para configuración).
     *
     * @param array $thresholds ['fast' => float, 'normal' => float]
     * @return self
     */
    public function setThresholds(array $thresholds): self
    {
        $this->thresholds = array_merge($this->thresholds, $thresholds);
        return $this;
    }
}
