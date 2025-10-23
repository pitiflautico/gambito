<?php

namespace App\Services\Modules\ScoringSystem;

/**
 * Gestor genérico de puntuación para juegos.
 *
 * Este módulo gestiona las puntuaciones de los jugadores de forma
 * independiente de la lógica específica de cálculo de cada juego.
 *
 * Responsabilidades:
 * - Mantener el estado de puntuaciones (scores array)
 * - Delegar cálculo de puntos al ScoreCalculator del juego
 * - Aplicar modificadores (bonus, penalizaciones)
 * - Calcular rankings y estadísticas
 * - Serialización del estado
 *
 * Patrón Strategy: El cálculo específico se delega al ScoreCalculator.
 */
class ScoreManager
{
    /**
     * Puntuaciones actuales de cada jugador.
     *
     * @var array<int, int> [player_id => score]
     */
    protected array $scores = [];

    /**
     * Calculador de puntos específico del juego.
     *
     * @var ScoreCalculatorInterface
     */
    protected ScoreCalculatorInterface $calculator;

    /**
     * Historial de eventos de puntuación (opcional, para debug/replay).
     *
     * @var array
     */
    protected array $scoreHistory = [];

    /**
     * Si se debe registrar historial de puntuaciones.
     *
     * @var bool
     */
    protected bool $trackHistory;

    /**
     * Constructor.
     *
     * @param array $playerIds Lista de IDs de jugadores
     * @param ScoreCalculatorInterface $calculator Calculador de puntos del juego
     * @param bool $trackHistory Si registrar historial de puntuaciones
     */
    public function __construct(
        array $playerIds,
        ScoreCalculatorInterface $calculator,
        bool $trackHistory = false
    ) {
        if (empty($playerIds)) {
            throw new \InvalidArgumentException('Se requiere al menos un jugador');
        }

        $this->calculator = $calculator;
        $this->trackHistory = $trackHistory;

        // Inicializar todos los jugadores con 0 puntos
        foreach ($playerIds as $playerId) {
            $this->scores[$playerId] = 0;
        }
    }

    /**
     * Otorgar puntos a un jugador por un evento.
     *
     * @param int $playerId ID del jugador
     * @param string $eventType Tipo de evento (ej: 'correct_answer')
     * @param array $context Contexto del evento
     * @return int Puntos otorgados
     * @throws \InvalidArgumentException Si el jugador no existe
     */
    public function awardPoints(int $playerId, string $eventType, array $context = []): int
    {
        if (!isset($this->scores[$playerId])) {
            throw new \InvalidArgumentException("Jugador {$playerId} no existe en el sistema de puntuación");
        }

        $points = $this->calculator->calculate($eventType, $context);
        $this->scores[$playerId] += $points;

        // Registrar en historial si está activado
        if ($this->trackHistory) {
            $this->scoreHistory[] = [
                'player_id' => $playerId,
                'event_type' => $eventType,
                'points' => $points,
                'total_score' => $this->scores[$playerId],
                'context' => $context,
                'timestamp' => now()->toIso8601String(),
            ];
        }

        return $points;
    }

    /**
     * Quitar puntos a un jugador (penalización).
     *
     * @param int $playerId ID del jugador
     * @param int $points Puntos a quitar
     * @return int Nueva puntuación del jugador
     */
    public function deductPoints(int $playerId, int $points): int
    {
        if (!isset($this->scores[$playerId])) {
            throw new \InvalidArgumentException("Jugador {$playerId} no existe");
        }

        $this->scores[$playerId] -= $points;

        // Evitar puntuaciones negativas (opcional, depende del juego)
        if ($this->scores[$playerId] < 0) {
            $this->scores[$playerId] = 0;
        }

        if ($this->trackHistory) {
            $this->scoreHistory[] = [
                'player_id' => $playerId,
                'event_type' => 'penalty',
                'points' => -$points,
                'total_score' => $this->scores[$playerId],
                'timestamp' => now()->toIso8601String(),
            ];
        }

        return $this->scores[$playerId];
    }

    /**
     * Establecer puntuación directa de un jugador.
     *
     * @param int $playerId ID del jugador
     * @param int $score Nueva puntuación
     * @return void
     */
    public function setScore(int $playerId, int $score): void
    {
        if (!isset($this->scores[$playerId])) {
            throw new \InvalidArgumentException("Jugador {$playerId} no existe");
        }

        $this->scores[$playerId] = $score;
    }

    /**
     * Obtener puntuación de un jugador.
     *
     * @param int $playerId ID del jugador
     * @return int Puntuación actual
     */
    public function getScore(int $playerId): int
    {
        if (!isset($this->scores[$playerId])) {
            throw new \InvalidArgumentException("Jugador {$playerId} no existe");
        }

        return $this->scores[$playerId];
    }

    /**
     * Obtener todas las puntuaciones.
     *
     * @return array<int, int> [player_id => score]
     */
    public function getScores(): array
    {
        return $this->scores;
    }

    /**
     * Obtener ranking ordenado por puntuación descendente.
     *
     * @return array Array de [player_id, score] ordenado
     */
    public function getRanking(): array
    {
        $scores = $this->scores;
        arsort($scores); // Ordenar descendente

        $ranking = [];
        $position = 1;
        foreach ($scores as $playerId => $score) {
            $ranking[] = [
                'position' => $position++,
                'player_id' => $playerId,
                'score' => $score,
            ];
        }

        return $ranking;
    }

    /**
     * Obtener el jugador con más puntos.
     *
     * @return array|null ['player_id' => id, 'score' => puntos] o null si hay empate
     */
    public function getWinner(): ?array
    {
        if (empty($this->scores)) {
            return null;
        }

        $maxScore = max($this->scores);
        $winners = array_filter($this->scores, fn($score) => $score === $maxScore);

        // Si hay empate, retornar null (el juego decide cómo resolverlo)
        if (count($winners) > 1) {
            return null;
        }

        $winnerId = array_key_first($winners);
        return [
            'player_id' => $winnerId,
            'score' => $maxScore,
        ];
    }

    /**
     * Obtener jugadores con puntuación más alta (puede haber empates).
     *
     * @return array Array de ['player_id' => id, 'score' => puntos]
     */
    public function getWinners(): array
    {
        if (empty($this->scores)) {
            return [];
        }

        $maxScore = max($this->scores);
        $winners = [];

        foreach ($this->scores as $playerId => $score) {
            if ($score === $maxScore) {
                $winners[] = [
                    'player_id' => $playerId,
                    'score' => $score,
                ];
            }
        }

        return $winners;
    }

    /**
     * Calcular estadísticas de puntuación.
     *
     * @return array Estadísticas (promedio, máximo, mínimo, total)
     */
    public function getStatistics(): array
    {
        if (empty($this->scores)) {
            return [
                'total_players' => 0,
                'total_points' => 0,
                'average_score' => 0,
                'highest_score' => 0,
                'lowest_score' => 0,
            ];
        }

        $scores = array_values($this->scores);

        return [
            'total_players' => count($scores),
            'total_points' => array_sum($scores),
            'average_score' => round(array_sum($scores) / count($scores), 2),
            'highest_score' => max($scores),
            'lowest_score' => min($scores),
        ];
    }

    /**
     * Añadir un nuevo jugador al sistema de puntuación.
     *
     * @param int $playerId ID del jugador
     * @param int $initialScore Puntuación inicial (default: 0)
     * @return void
     */
    public function addPlayer(int $playerId, int $initialScore = 0): void
    {
        if (isset($this->scores[$playerId])) {
            throw new \InvalidArgumentException("Jugador {$playerId} ya existe");
        }

        $this->scores[$playerId] = $initialScore;
    }

    /**
     * Eliminar un jugador del sistema de puntuación.
     *
     * @param int $playerId ID del jugador
     * @return int Puntuación final del jugador eliminado
     */
    public function removePlayer(int $playerId): int
    {
        if (!isset($this->scores[$playerId])) {
            throw new \InvalidArgumentException("Jugador {$playerId} no existe");
        }

        $finalScore = $this->scores[$playerId];
        unset($this->scores[$playerId]);

        return $finalScore;
    }

    /**
     * Resetear todas las puntuaciones a 0.
     *
     * @return void
     */
    public function reset(): void
    {
        foreach ($this->scores as $playerId => $score) {
            $this->scores[$playerId] = 0;
        }

        if ($this->trackHistory) {
            $this->scoreHistory = [];
        }
    }

    /**
     * Obtener historial de puntuaciones.
     *
     * @return array Historial de eventos
     */
    public function getHistory(): array
    {
        return $this->scoreHistory;
    }

    /**
     * Serializar el estado a array para persistencia.
     *
     * @return array Estado serializado
     */
    public function toArray(): array
    {
        return [
            'scoring_system' => [
                'scores' => $this->scores,
                'score_history' => $this->trackHistory ? $this->scoreHistory : [],
            ]
        ];
    }

    /**
     * Restaurar el estado desde array.
     *
     * @param array $playerIds Lista de IDs de jugadores
     * @param array $data Estado serializado
     * @param ScoreCalculatorInterface $calculator Calculador de puntos
     * @param bool $trackHistory Si registrar historial
     * @return self Nueva instancia con estado restaurado
     */
    public static function fromArray(
        array $playerIds,
        array $data,
        ScoreCalculatorInterface $calculator,
        bool $trackHistory = false
    ): self {
        $instance = new self($playerIds, $calculator, $trackHistory);

        // Soporte para ambos formatos: nuevo (scoring_system) y legacy (claves directas)
        $scoringData = $data['scoring_system'] ?? $data;

        if (isset($scoringData['scores'])) {
            $instance->scores = $scoringData['scores'];
        }

        if ($trackHistory && isset($scoringData['score_history'])) {
            $instance->scoreHistory = $scoringData['score_history'];
        }

        return $instance;
    }
}
