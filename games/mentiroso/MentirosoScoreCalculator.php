<?php

namespace Games\Mentiroso;

use App\Services\Modules\ScoringSystem\ScoreCalculatorInterface;

class MentirosoScoreCalculator implements ScoreCalculatorInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'orador_deceives_majority' => 10,
            'orador_deceives_all' => 15,
            'voter_correct' => 5,
            'voter_incorrect' => 0,
        ], $config);
    }

    public function calculate(string $reason, array $context = []): int
    {
        return match($reason) {
            'orador_deceives_majority' => $this->calculateOradorDeceivesMajority($context),
            'orador_deceives_all' => $this->calculateOradorDeceivesAll($context),
            'voter_correct' => $this->calculateVoterCorrect($context),
            'voter_incorrect' => $this->calculateVoterIncorrect($context),
            default => 0,
        };
    }

    /**
     * Orador engaña a la mayoría (>50%)
     */
    private function calculateOradorDeceivesMajority(array $context): int
    {
        return $this->config['orador_deceives_majority'];
    }

    /**
     * Orador engaña a todos (100%)
     * Bonus especial cuando nadie acierta
     */
    private function calculateOradorDeceivesAll(array $context): int
    {
        return $this->config['orador_deceives_all'];
    }

    /**
     * Votante acierta la verdad
     */
    private function calculateVoterCorrect(array $context): int
    {
        return $this->config['voter_correct'];
    }

    /**
     * Votante se equivoca
     */
    private function calculateVoterIncorrect(array $context): int
    {
        return $this->config['voter_incorrect'];
    }

    /**
     * Obtener configuración de puntuación
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Validar si un evento es válido para puntuación
     */
    public function supportsEvent(string $eventType): bool
    {
        return in_array($eventType, [
            'orador_deceives_majority',
            'orador_deceives_all',
            'voter_correct',
            'voter_incorrect',
        ]);
    }
}
