<?php

namespace App\Contracts;

use App\Models\GameMatch;
use App\Models\Player;

/**
 * GameRules - Reglas del Juego Completo
 *
 * Esta clase abstracta define las reglas que determinan:
 * - Cuándo termina el juego completo
 * - Quién gana el juego
 * - Cuántas rondas tiene el juego
 * - Cuándo iniciar una nueva ronda
 *
 * Cada juego debe implementar su propia clase de reglas extendiendo esta clase.
 *
 * Ejemplos:
 * - TriviaGameRules: "10 preguntas, gana quien tenga más puntos"
 * - PictionaryGameRules: "Primero en 5 puntos gana"
 * - UNOGameRules: "Primero en quedarse sin cartas gana"
 */
abstract class GameRules
{
    /**
     * Determinar si el juego ha terminado.
     *
     * Este método consulta el estado del match para decidir si el juego completo
     * ha finalizado según las reglas específicas del juego.
     *
     * Ejemplos:
     * - Trivia: true cuando se respondieron todas las preguntas
     * - Pictionary: true cuando alguien llega a 5 puntos
     * - UNO: true cuando alguien se queda sin cartas
     *
     * @param GameMatch $match La partida actual
     * @return bool true si el juego terminó, false si continúa
     */
    abstract public function isGameComplete(GameMatch $match): bool;

    /**
     * Determinar quién ganó el juego.
     *
     * Este método solo debe llamarse cuando isGameComplete() retorna true.
     * Determina el ganador según las reglas específicas del juego.
     *
     * Ejemplos:
     * - Trivia: El jugador con más puntos al final
     * - Pictionary: El primer jugador en llegar a 5 puntos
     * - UNO: El primer jugador en quedarse sin cartas
     *
     * @param GameMatch $match La partida actual
     * @return Player|null El jugador ganador, o null si hay empate o no hay ganador
     */
    abstract public function getWinner(GameMatch $match): ?Player;

    /**
     * Obtener el número total de rondas del juego.
     *
     * Para juegos con rondas fijas (ej: Trivia con 10 preguntas), retorna ese número.
     * Para juegos con rondas ilimitadas (ej: Pictionary hasta 5 puntos), retorna -1 o PHP_INT_MAX.
     *
     * Ejemplos:
     * - Trivia: 10 (número de preguntas)
     * - Pictionary: -1 (ilimitado hasta que alguien gane)
     * - UNO: -1 (ilimitado hasta que alguien gane)
     *
     * @param GameMatch $match La partida actual
     * @return int Número total de rondas, o -1 si es ilimitado
     */
    abstract public function getTotalRounds(GameMatch $match): int;

    /**
     * Determinar si debe iniciar una nueva ronda.
     *
     * Este método se llama después de terminar una ronda para decidir si
     * el juego debe continuar con una nueva ronda o ha terminado.
     *
     * Típicamente retorna:
     * - true si el juego no ha terminado (isGameComplete() es false)
     * - false si el juego ya terminó
     *
     * @param GameMatch $match La partida actual
     * @return bool true si debe iniciar nueva ronda, false si no
     */
    abstract public function shouldStartNewRound(GameMatch $match): bool;

    /**
     * Calcular los resultados finales del juego.
     *
     * Este método se llama cuando el juego termina para obtener:
     * - El ganador
     * - El ranking completo de jugadores
     * - Estadísticas del juego
     *
     * @param GameMatch $match La partida actual
     * @return array Estructura:
     * [
     *     'winner' => Player|null,
     *     'ranking' => [
     *         ['position' => 1, 'player_id' => 1, 'player_name' => 'Alice', 'score' => 850],
     *         ['position' => 2, 'player_id' => 2, 'player_name' => 'Bob', 'score' => 720],
     *     ],
     *     'statistics' => [
     *         'total_rounds' => 10,
     *         'duration_seconds' => 320,
     *         // ... estadísticas específicas del juego
     *     ]
     * ]
     */
    abstract public function getFinalResults(GameMatch $match): array;

    // ========================================================================
    // MÉTODOS AUXILIARES (Opcionales de sobrescribir)
    // ========================================================================

    /**
     * Verificar si hay un empate en el juego.
     *
     * Por defecto, verifica si getWinner() retorna null cuando el juego terminó.
     * Los juegos pueden sobrescribir este método para lógica más específica.
     *
     * @param GameMatch $match La partida actual
     * @return bool true si hay empate
     */
    public function isTie(GameMatch $match): bool
    {
        return $this->isGameComplete($match) && $this->getWinner($match) === null;
    }

    /**
     * Obtener el progreso del juego (0-100%).
     *
     * Por defecto, calcula basándose en rondas completadas vs totales.
     * Los juegos con rondas ilimitadas deben sobrescribir este método.
     *
     * @param GameMatch $match La partida actual
     * @return float Progreso de 0 a 100
     */
    public function getProgress(GameMatch $match): float
    {
        $totalRounds = $this->getTotalRounds($match);

        if ($totalRounds <= 0) {
            // Rondas ilimitadas, no se puede calcular progreso fijo
            return 0;
        }

        $roundManager = \App\Services\Modules\RoundSystem\RoundManager::fromArray($match->game_state);
        $currentRound = $roundManager->getCurrentRound();

        return min(100, ($currentRound / $totalRounds) * 100);
    }
}
