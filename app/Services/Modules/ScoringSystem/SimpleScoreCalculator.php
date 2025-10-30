<?php

namespace App\Services\Modules\ScoringSystem;

/**
 * SimpleScoreCalculator - Calculator por defecto sin lógica especial
 *
 * Este calculator acepta cualquier evento y siempre devuelve los
 * puntos que se le pasen en el contexto. Sin multiplicadores ni
 * bonificaciones. Útil para juegos que solo necesitan acumular
 * puntos básicos.
 */
class SimpleScoreCalculator implements ScoreCalculatorInterface
{
    /**
     * Calcular puntos para un evento específico.
     *
     * @param string $eventType Tipo de evento (ignorado)
     * @param array $context Contexto del evento (debe incluir 'points')
     * @return int Puntos calculados
     */
    public function calculate(string $eventType, array $context): int
    {
        // Retornar los puntos pasados en el contexto, o 0 si no hay
        return $context['points'] ?? 0;
    }

    /**
     * Obtener la configuración de puntuación del juego.
     *
     * @return array Configuración de puntuación
     */
    public function getConfig(): array
    {
        return [
            'type' => 'simple',
            'description' => 'Simple score calculator without modifiers',
        ];
    }

    /**
     * Validar si un evento es válido para puntuación.
     *
     * @param string $eventType Tipo de evento
     * @return bool True siempre (acepta cualquier evento)
     */
    public function supportsEvent(string $eventType): bool
    {
        // Aceptar cualquier evento
        return true;
    }
}
