<?php

namespace Games\Uno;

use App\Contracts\ScoreCalculatorInterface;

/**
 * UNO Score Calculator
 *
 * Calcula puntos según las reglas oficiales de UNO:
 * - El ganador suma los puntos de las cartas restantes de los demás
 * - Cartas numéricas: valor numérico
 * - Cartas especiales (Skip, Reverse, +2): 20 puntos
 * - Cartas Wild y Wild +4: 50 puntos
 */
class UnoScoreCalculator implements ScoreCalculatorInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'card_values' => [
                '0' => 0,
                '1' => 1,
                '2' => 2,
                '3' => 3,
                '4' => 4,
                '5' => 5,
                '6' => 6,
                '7' => 7,
                '8' => 8,
                '9' => 9,
                'skip' => 20,
                'reverse' => 20,
                'draw2' => 20,
                'wild' => 50,
                'wild_draw4' => 50,
            ]
        ], $config);
    }

    /**
     * Calcular puntos según la acción
     *
     * @param string $event Evento que genera puntos
     * @param array $context Contexto con información adicional
     * @return array ['player_id' => int, 'points' => int, 'reason' => string]
     */
    public function calculate(string $event, array $context): array
    {
        return match ($event) {
            'round_won' => $this->calculateRoundWin($context),
            'game_won' => $this->calculateGameWin($context),
            default => throw new \InvalidArgumentException("Evento no soportado: {$event}")
        };
    }

    /**
     * Calcular puntos al ganar una ronda
     *
     * @param array $context ['player_id' => int, 'opponent_hands' => array]
     * @return array
     */
    protected function calculateRoundWin(array $context): array
    {
        $playerId = $context['player_id'];
        $opponentHands = $context['opponent_hands'] ?? [];

        $totalPoints = 0;

        // Sumar el valor de todas las cartas de los oponentes
        foreach ($opponentHands as $hand) {
            foreach ($hand as $card) {
                $totalPoints += $this->getCardValue($card);
            }
        }

        return [
            'player_id' => $playerId,
            'points' => $totalPoints,
            'reason' => 'Ganó la ronda',
            'breakdown' => [
                'cards_counted' => array_sum(array_map('count', $opponentHands)),
                'total_value' => $totalPoints
            ]
        ];
    }

    /**
     * Calcular puntos al ganar el juego completo (bonus)
     *
     * @param array $context ['player_id' => int]
     * @return array
     */
    protected function calculateGameWin(array $context): array
    {
        return [
            'player_id' => $context['player_id'],
            'points' => 0, // Ya se sumaron en las rondas
            'reason' => 'Ganó el juego',
            'breakdown' => []
        ];
    }

    /**
     * Obtener el valor de una carta
     *
     * @param array $card ['type' => 'number|special|wild', 'value' => mixed, 'color' => string]
     * @return int
     */
    protected function getCardValue(array $card): int
    {
        $type = $card['type'];
        $value = $card['value'];

        if ($type === 'number') {
            return (int) $value;
        }

        if ($type === 'special') {
            return $this->config['card_values'][$value] ?? 20;
        }

        if ($type === 'wild') {
            return $this->config['card_values'][$value] ?? 50;
        }

        return 0;
    }

    /**
     * Verificar si el calculador soporta un evento
     *
     * @param string $event
     * @return bool
     */
    public function supportsEvent(string $event): bool
    {
        return in_array($event, [
            'round_won',
            'game_won'
        ]);
    }

    /**
     * Obtener configuración del calculador
     *
     * @return array
     */
    public function getConfig(): array
    {
        return [
            'supported_events' => [
                'round_won' => 'Puntos por ganar una ronda',
                'game_won' => 'Puntos por ganar el juego completo'
            ],
            'card_values' => $this->config['card_values']
        ];
    }
}
