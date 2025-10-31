<?php

namespace Games\Trivia;

use App\Services\Modules\ScoringSystem\ScoreCalculatorInterface;

class TriviaScoreCalculator implements ScoreCalculatorInterface
{
    public function __construct()
    {
    }

    public function calculate(string $eventType, array $context): int
    {
        // Base: 10 puntos por acierto; otros 0
        if ($eventType === 'correct_answer') {
            return (int)($context['points'] ?? 10);
        }
        return 0;
    }

    public function getConfig(): array
    {
        return [
            'correct_answer_points' => 10,
            'description' => 'Trivia: puntos por respuesta correcta',
        ];
    }

    public function supportsEvent(string $eventType): bool
    {
        return in_array($eventType, ['correct_answer', 'wrong_answer', 'default'], true);
    }
}


