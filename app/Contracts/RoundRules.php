<?php

namespace App\Contracts;

use App\Models\GameMatch;
use App\Models\Player;

/**
 * RoundRules - Reglas de Una Ronda Individual
 *
 * Esta clase abstracta define las reglas que determinan:
 * - Cuándo termina una ronda
 * - Quién gana una ronda
 * - Cómo se calculan los puntos de una ronda
 * - Qué acciones son válidas en una ronda
 *
 * Cada juego debe implementar su propia clase de reglas extendiendo esta clase.
 *
 * Ejemplos:
 * - TriviaRoundRules: "Primera respuesta correcta termina la ronda, +100 puntos + bonus velocidad"
 * - PictionaryRoundRules: "Primera adivinación correcta termina la ronda, +1 punto al dibujante y adivinador"
 * - UNORoundRules: "Jugar una carta válida o robar termina el turno"
 */
abstract class RoundRules
{
    /**
     * Determinar si la ronda debe terminar después de una acción.
     *
     * Este método se llama cada vez que un jugador realiza una acción
     * para decidir si la ronda debe finalizar.
     *
     * Ejemplos:
     * - Trivia: true cuando alguien responde correctamente
     * - Pictionary: true cuando alguien adivina la palabra
     * - UNO: true cuando el jugador juega una carta válida o roba
     *
     * @param GameMatch $match La partida actual
     * @param array $actionResult Resultado de la acción procesada:
     * [
     *     'success' => bool,
     *     'player_id' => int,
     *     'action' => string,
     *     'is_correct' => bool, // para respuestas
     *     // ... datos específicos del juego
     * ]
     * @return bool true si la ronda debe terminar, false si continúa
     */
    abstract public function shouldEndRound(GameMatch $match, array $actionResult): bool;

    /**
     * Validar si un jugador puede realizar una acción en el estado actual.
     *
     * Este método se llama ANTES de procesar la acción para validar si es permitida.
     *
     * Ejemplos de validaciones:
     * - Trivia: false si el jugador ya respondió esta pregunta
     * - Pictionary: false si el jugador es el dibujante (no puede adivinar)
     * - UNO: false si la carta no es jugable según las reglas
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador que intenta la acción
     * @param string $action Nombre de la acción (ej: 'answer', 'draw', 'guess_word')
     * @param array $data Datos adicionales de la acción
     * @return bool true si la acción es válida, false si no
     */
    abstract public function isValidAction(
        GameMatch $match,
        Player $player,
        string $action,
        array $data
    ): bool;

    /**
     * Calcular los puntos que gana cada jugador en esta ronda.
     *
     * Este método se llama cuando la ronda termina para calcular
     * cuántos puntos gana cada jugador.
     *
     * Ejemplos:
     * - Trivia: +100 base + bonus por velocidad para respuesta correcta
     * - Pictionary: +1 para dibujante y adivinador
     * - UNO: 0 (no hay puntos por ronda)
     *
     * @param GameMatch $match La partida actual
     * @return array Array de player_id => points_earned:
     * [
     *     1 => 150,  // Alice ganó 150 puntos
     *     2 => 0,    // Bob no ganó puntos
     *     3 => 75,   // Charlie ganó 75 puntos
     * ]
     */
    abstract public function calculateRoundPoints(GameMatch $match): array;

    /**
     * Determinar quién ganó esta ronda específica.
     *
     * Este método retorna el jugador que ganó la ronda, o null si
     * no hay un ganador claro (todos fallaron, empate, etc.).
     *
     * Ejemplos:
     * - Trivia: El primero en responder correctamente
     * - Pictionary: El primero en adivinar
     * - UNO: null (no hay ganador por ronda, solo por juego)
     *
     * @param GameMatch $match La partida actual
     * @return Player|null El jugador ganador de la ronda, o null
     */
    abstract public function getRoundWinner(GameMatch $match): ?Player;

    /**
     * Obtener los resultados completos de la ronda para mostrar en UI.
     *
     * Este método retorna toda la información necesaria para mostrar
     * los resultados de la ronda a los jugadores.
     *
     * La estructura del array retornado debe incluir:
     * - winner: El ganador de la ronda
     * - points: Puntos ganados por cada jugador
     * - details: Información adicional específica del juego
     *
     * @param GameMatch $match La partida actual
     * @return array Estructura:
     * [
     *     'winner' => Player|null,
     *     'points' => [player_id => points],
     *     'details' => [
     *         // Información específica del juego para mostrar en UI
     *         'correct_answer' => 'Madrid',
     *         'player_responses' => [
     *             1 => ['answer' => 0, 'correct' => true, 'time' => 3.5],
     *             2 => ['answer' => 1, 'correct' => false, 'time' => 5.2],
     *         ]
     *     ]
     * ]
     */
    abstract public function getRoundResults(GameMatch $match): array;

    // ========================================================================
    // MÉTODOS AUXILIARES (Opcionales de sobrescribir)
    // ========================================================================

    /**
     * Verificar si todos los jugadores activos completaron su acción en la ronda.
     *
     * Por defecto, retorna false (los juegos deben implementar su lógica).
     * Útil para juegos donde la ronda termina cuando todos respondieron.
     *
     * @param GameMatch $match La partida actual
     * @return bool true si todos completaron su acción
     */
    public function haveAllPlayersCompleted(GameMatch $match): bool
    {
        return false;
    }

    /**
     * Obtener el tiempo restante de la ronda (en segundos).
     *
     * Por defecto, retorna null (sin límite de tiempo).
     * Los juegos con timer deben sobrescribir este método.
     *
     * @param GameMatch $match La partida actual
     * @return int|null Segundos restantes, o null si no hay límite
     */
    public function getRemainingTime(GameMatch $match): ?int
    {
        $gameState = $match->game_state;
        $timerData = $gameState['timer_system'] ?? null;

        if (!$timerData) {
            return null;
        }

        $timerService = \App\Services\Modules\TimerSystem\TimerService::fromArray($gameState);

        // Buscar el timer activo de la ronda actual
        if (isset($timerData['active_timers']['question_timer'])) {
            return $timerService->getRemainingTime('question_timer');
        }

        if (isset($timerData['active_timers']['round_timer'])) {
            return $timerService->getRemainingTime('round_timer');
        }

        return null;
    }

    /**
     * Verificar si el tiempo de la ronda se agotó.
     *
     * Por defecto, consulta si el timer expiró.
     *
     * @param GameMatch $match La partida actual
     * @return bool true si el tiempo se agotó
     */
    public function hasTimeExpired(GameMatch $match): bool
    {
        $remainingTime = $this->getRemainingTime($match);

        return $remainingTime !== null && $remainingTime <= 0;
    }

    /**
     * Obtener el número de jugadores que han completado su acción.
     *
     * Por defecto, retorna 0. Los juegos deben sobrescribir según su lógica.
     *
     * @param GameMatch $match La partida actual
     * @return int Número de jugadores que completaron su acción
     */
    public function getCompletedPlayersCount(GameMatch $match): int
    {
        return 0;
    }
}
