<?php

namespace App\Services\Modules\ScoringSystem;

/**
 * Interface para calculadores de puntuación específicos de cada juego.
 *
 * Cada juego implementa esta interface con su lógica de cálculo de puntos.
 * Esto permite que ScoreManager sea genérico y reutilizable.
 *
 * Ejemplos:
 * - PictionaryScoreCalculator: Puntos basados en tiempo de respuesta
 * - TriviaScoreCalculator: Puntos basados en dificultad de pregunta y tiempo
 * - UNOScoreCalculator: Puntos basados en cartas restantes
 */
interface ScoreCalculatorInterface
{
    /**
     * Calcular puntos para un evento específico.
     *
     * @param string $eventType Tipo de evento (ej: 'correct_answer', 'drawer_bonus', 'round_win')
     * @param array $context Contexto del evento (tiempo, dificultad, jugadores, etc.)
     * @return int Puntos calculados
     */
    public function calculate(string $eventType, array $context): int;

    /**
     * Obtener la configuración de puntuación del juego.
     *
     * Devuelve información sobre cómo se calculan los puntos para
     * mostrar al jugador o para validación.
     *
     * @return array Configuración de puntuación
     */
    public function getConfig(): array;

    /**
     * Validar si un evento es válido para puntuación.
     *
     * @param string $eventType Tipo de evento
     * @return bool True si el evento otorga puntos
     */
    public function supportsEvent(string $eventType): bool;
}
