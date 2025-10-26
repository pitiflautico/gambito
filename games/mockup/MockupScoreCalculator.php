<?php

namespace Games\Mockup;

use App\Services\Modules\ScoringSystem\ScoreCalculatorInterface;

/**
 * Calculador de puntos para el juego Mockup.
 *
 * Lógica simple: 10 puntos por acción.
 */
class MockupScoreCalculator implements ScoreCalculatorInterface
{
    public function __construct()
    {
        // Sin configuración adicional para mockup
    }

    /**
     * Calcular puntos para un evento del juego.
     *
     * @param string $eventType Tipo de evento
     * @param array $context Contexto del evento
     * @return int Puntos calculados
     */
    public function calculate(string $eventType, array $context): int
    {
        // Mockup: 10 puntos por cualquier acción
        return 10;
    }

    /**
     * Obtener configuración del calculador.
     *
     * @return array
     */
    public function getConfig(): array
    {
        return [
            'action_points' => 10,
            'description' => 'Mockup game: 10 points per action',
        ];
    }

    /**
     * Verificar si el calculador soporta un tipo de evento.
     *
     * @param string $eventType Tipo de evento
     * @return bool
     */
    public function supportsEvent(string $eventType): bool
    {
        // Mockup soporta cualquier evento
        return true;
    }
}

