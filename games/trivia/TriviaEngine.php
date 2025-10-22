<?php

namespace Games\Trivia;

use App\Contracts\GameEngineInterface;
use App\Models\GameMatch;
use App\Models\Player;
use App\Services\Modules\ScoringSystem\ScoreManager;
use App\Services\Modules\TimerSystem\TimerService;
use App\Services\Modules\RoundSystem\RoundManager;
use App\Services\Modules\TurnSystem\TurnManager;
use Games\Trivia\Events\QuestionStartedEvent;
use Games\Trivia\Events\PlayerAnsweredEvent;
use Games\Trivia\Events\QuestionEndedEvent;
use Illuminate\Support\Facades\Log;

/**
 * Trivia Game Engine
 *
 * Juego de preguntas y respuestas donde todos los jugadores responden simultáneamente.
 *
 * Módulos utilizados:
 * - Turn System (modo: simultaneous)
 * - Round System (1 ronda = 1 pregunta)
 * - Scoring System (puntos + bonus por velocidad)
 * - Timer System (tiempo por pregunta)
 *
 * Flujo del juego:
 * 1. Initialize: Cargar preguntas, inicializar módulos
 * 2. Question phase: Mostrar pregunta, jugadores responden
 * 3. Results phase: Mostrar respuesta correcta y puntos
 * 4. Repeat hasta completar todas las preguntas
 * 5. Final results: Mostrar ganador y ranking
 */
class TriviaEngine implements GameEngineInterface
{
    /**
     * Inicializar el juego.
     */
    public function initialize(GameMatch $match): void
    {
        Log::info("Initializing Trivia game", ['match_id' => $match->id]);

        // Verificar que hay suficientes jugadores
        $players = $match->room->players;

        if ($players->count() < 2) {
            Log::error("Not enough players to start Trivia", [
                'match_id' => $match->id,
                'player_count' => $players->count()
            ]);
            throw new \Exception('Se requieren al menos 2 jugadores para jugar Trivia');
        }

        // Cargar configuración del juego
        $config = json_decode(file_get_contents(base_path('games/trivia/config.json')), true);

        $questionsPerGame = $config['settings']['questions_per_game']['default'] ?? 10;
        $timePerQuestion = $config['settings']['time_per_question']['default'] ?? 15;
        $difficulty = $config['settings']['difficulty']['default'] ?? 'mixed';
        $category = $config['settings']['category']['default'] ?? 'mixed';

        // Cargar banco de preguntas
        $questionsPath = base_path('games/trivia/assets/questions.json');

        if (!file_exists($questionsPath)) {
            throw new \Exception('Questions file not found');
        }

        $allQuestions = json_decode(file_get_contents($questionsPath), true);

        // Seleccionar preguntas según configuración
        $selectedQuestions = $this->selectQuestions(
            $allQuestions,
            $questionsPerGame,
            $difficulty,
            $category
        );

        if (count($selectedQuestions) < $questionsPerGame) {
            Log::warning("Not enough questions available", [
                'match_id' => $match->id,
                'requested' => $questionsPerGame,
                'available' => count($selectedQuestions)
            ]);
        }

        // ========================================================================
        // MÓDULO: Turn System (modo simultaneous)
        // ========================================================================
        $playerIds = $players->pluck('id')->toArray();

        $turnManager = new TurnManager(
            playerIds: $playerIds,
            mode: 'simultaneous' // Todos juegan a la vez
        );

        // ========================================================================
        // MÓDULO: Round System
        // ========================================================================
        $roundManager = new RoundManager(
            turnManager: $turnManager,
            totalRounds: count($selectedQuestions), // 1 ronda por pregunta
            currentRound: 1
        );

        // ========================================================================
        // MÓDULO: Scoring System
        // ========================================================================
        $scoreManager = new ScoreManager(
            playerIds: $playerIds,
            trackHistory: true,
            allowNegativeScores: false
        );

        // ========================================================================
        // MÓDULO: Timer System
        // ========================================================================
        $timerService = new TimerService();

        // Obtener primera pregunta
        $firstQuestion = $selectedQuestions[0];

        // Estado inicial del juego
        $match->game_state = array_merge([
            'phase' => 'question',
            'questions' => $selectedQuestions,
            'current_question_index' => 0,
            'current_question' => $firstQuestion,
            'time_per_question' => $timePerQuestion,
            'player_answers' => [], // {player_id: {answer: 0, seconds_elapsed: 5.2}}
            'question_start_time' => now()->timestamp,
        ], $roundManager->toArray(), $scoreManager->toArray(), $timerService->toArray());

        $match->save();

        // Iniciar timer de la pregunta
        $timerService = TimerService::fromArray($match->game_state);
        $timerService->startTimer('question_timer', $timePerQuestion);

        $match->game_state = array_merge($match->game_state, $timerService->toArray());
        $match->save();

        Log::info("Trivia initialized successfully", [
            'match_id' => $match->id,
            'players' => count($playerIds),
            'questions' => count($selectedQuestions),
            'time_per_question' => $timePerQuestion
        ]);

        // Broadcast inicio de pregunta
        event(new QuestionStartedEvent(
            $match,
            $firstQuestion['question'],
            $firstQuestion['options'],
            1,
            count($selectedQuestions)
        ));
    }

    /**
     * Procesar una acción de un jugador.
     */
    public function processAction(GameMatch $match, Player $player, string $action, array $data): array
    {
        Log::info("Processing action", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'action' => $action
        ]);

        return match ($action) {
            'answer' => $this->handleAnswer($match, $player, $data),
            default => ['success' => false, 'error' => 'Acción no soportada']
        };
    }

    /**
     * Avanzar el juego a la siguiente fase.
     */
    public function advancePhase(GameMatch $match): void
    {
        $gameState = $match->game_state;
        $currentPhase = $gameState['phase'];

        Log::info("Advancing phase", [
            'match_id' => $match->id,
            'current_phase' => $currentPhase
        ]);

        switch ($currentPhase) {
            case 'question':
                // Terminar pregunta, mostrar resultados
                $this->endQuestion($match);
                break;

            case 'results':
                // Ir a siguiente pregunta o finalizar juego
                $this->nextQuestion($match);
                break;

            default:
                Log::warning("Unknown phase", ['phase' => $currentPhase]);
                break;
        }
    }

    /**
     * Finalizar el juego y calcular resultados.
     */
    public function finalize(GameMatch $match): array
    {
        $gameState = $match->game_state;

        Log::info("Finalizing Trivia game", ['match_id' => $match->id]);

        // Cambiar a fase final
        $gameState['phase'] = 'final_results';
        $match->game_state = $gameState;
        $match->save();

        // Obtener ranking final
        $scoreManager = ScoreManager::fromArray(
            playerIds: array_keys($gameState['scores']),
            data: $gameState
        );

        $rawRanking = $scoreManager->getRanking();

        // Enriquecer ranking con información de jugadores
        $ranking = [];
        foreach ($rawRanking as $entry) {
            $player = Player::find($entry['player_id']);

            if ($player) {
                $ranking[] = [
                    'position' => $entry['position'],
                    'player_id' => $entry['player_id'],
                    'player_name' => $player->name,
                    'score' => $entry['score'],
                ];
            }
        }

        $winner = !empty($ranking) ? $ranking[0] : null;

        // Obtener Round Manager para estadísticas
        $roundManager = RoundManager::fromArray($gameState);

        $statistics = [
            'total_questions' => $roundManager->getTotalRounds(),
            'questions_answered' => $roundManager->getCurrentRound() - 1,
            'players_count' => count($gameState['scores']),
            'highest_score' => $winner ? $winner['score'] : 0,
            'average_score' => !empty($gameState['scores'])
                ? round(array_sum($gameState['scores']) / count($gameState['scores']), 2)
                : 0,
        ];

        Log::info("Trivia game finalized", [
            'match_id' => $match->id,
            'winner' => $winner ? $winner['player_name'] : 'None',
            'statistics' => $statistics
        ]);

        return [
            'winner' => $winner,
            'ranking' => $ranking,
            'statistics' => $statistics,
        ];
    }

    // ========================================================================
    // MÉTODOS PRIVADOS (Lógica interna)
    // ========================================================================

    /**
     * Manejar respuesta de un jugador.
     */
    private function handleAnswer(GameMatch $match, Player $player, array $data): array
    {
        $gameState = $match->game_state;

        // Verificar que estamos en fase de pregunta
        if ($gameState['phase'] !== 'question') {
            return ['success' => false, 'error' => 'No hay pregunta activa'];
        }

        // Verificar que el jugador no haya respondido ya
        if (isset($gameState['player_answers'][$player->id])) {
            return ['success' => false, 'error' => 'Ya has respondido esta pregunta'];
        }

        $answerIndex = $data['answer'] ?? null;

        if ($answerIndex === null || !is_numeric($answerIndex)) {
            return ['success' => false, 'error' => 'Respuesta inválida'];
        }

        // Calcular tiempo transcurrido desde inicio de pregunta
        $secondsElapsed = now()->timestamp - $gameState['question_start_time'];

        // Guardar respuesta del jugador
        $gameState['player_answers'][$player->id] = [
            'answer' => (int)$answerIndex,
            'seconds_elapsed' => $secondsElapsed,
            'timestamp' => now()->timestamp,
        ];

        $match->game_state = $gameState;
        $match->save();

        Log::info("Player answered", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'answer' => $answerIndex,
            'seconds_elapsed' => $secondsElapsed
        ]);

        // Broadcast respuesta
        event(new PlayerAnsweredEvent($match, $player));

        // Verificar si todos han respondido
        $roundManager = RoundManager::fromArray($gameState);
        $totalPlayers = count($roundManager->getTurnOrder());
        $answeredPlayers = count($gameState['player_answers']);

        if ($answeredPlayers >= $totalPlayers) {
            // Todos respondieron, terminar pregunta automáticamente
            $this->endQuestion($match);
        }

        return [
            'success' => true,
            'message' => 'Respuesta registrada',
            'answered_players' => $answeredPlayers,
            'total_players' => $totalPlayers
        ];
    }

    /**
     * Terminar pregunta y calcular puntos.
     */
    private function endQuestion(GameMatch $match): void
    {
        $gameState = $match->game_state;
        $currentQuestion = $gameState['current_question'];
        $correctAnswer = $currentQuestion['correct'];

        Log::info("Ending question", [
            'match_id' => $match->id,
            'question_index' => $gameState['current_question_index'],
            'correct_answer' => $correctAnswer
        ]);

        // Calcular puntos para cada jugador
        $scoreManager = ScoreManager::fromArray(
            playerIds: array_keys($gameState['scores']),
            data: $gameState,
            calculator: new TriviaScoreCalculator()
        );

        $questionResults = [];

        foreach ($gameState['player_answers'] as $playerId => $answerData) {
            $isCorrect = $answerData['answer'] === $correctAnswer;

            if ($isCorrect) {
                // Otorgar puntos
                $points = $scoreManager->awardPoints($playerId, 'correct_answer', [
                    'seconds_elapsed' => $answerData['seconds_elapsed'],
                    'time_limit' => $gameState['time_per_question']
                ]);

                $questionResults[$playerId] = [
                    'correct' => true,
                    'points_earned' => $points,
                    'seconds_elapsed' => $answerData['seconds_elapsed']
                ];
            } else {
                $questionResults[$playerId] = [
                    'correct' => false,
                    'points_earned' => 0,
                    'seconds_elapsed' => $answerData['seconds_elapsed']
                ];
            }
        }

        // Actualizar scores en game_state
        $gameState['scores'] = $scoreManager->getScores();
        $gameState['question_results'] = $questionResults;
        $gameState['phase'] = 'results';

        $match->game_state = $gameState;
        $match->save();

        // Broadcast resultados de pregunta
        event(new QuestionEndedEvent(
            $match,
            $correctAnswer,
            $questionResults,
            $gameState['scores']
        ));
    }

    /**
     * Ir a siguiente pregunta o finalizar juego.
     */
    private function nextQuestion(GameMatch $match): void
    {
        $gameState = $match->game_state;

        // Avanzar ronda
        $roundManager = RoundManager::fromArray($gameState);
        $roundManager->nextTurn();

        // Verificar si el juego terminó
        if ($roundManager->isGameComplete()) {
            $this->finalize($match);
            return;
        }

        // Obtener siguiente pregunta
        $nextIndex = $gameState['current_question_index'] + 1;

        if ($nextIndex >= count($gameState['questions'])) {
            // No hay más preguntas, finalizar
            $this->finalize($match);
            return;
        }

        $nextQuestion = $gameState['questions'][$nextIndex];

        // Reiniciar estado para nueva pregunta
        $gameState['current_question_index'] = $nextIndex;
        $gameState['current_question'] = $nextQuestion;
        $gameState['player_answers'] = [];
        $gameState['question_start_time'] = now()->timestamp;
        $gameState['phase'] = 'question';
        $gameState['question_results'] = [];

        // Actualizar Round Manager
        $gameState = array_merge($gameState, $roundManager->toArray());

        // Iniciar nuevo timer
        $timerService = TimerService::fromArray($gameState);
        $timerService->cancelTimer('question_timer');
        $timerService->startTimer('question_timer', $gameState['time_per_question']);

        $gameState = array_merge($gameState, $timerService->toArray());

        $match->game_state = $gameState;
        $match->save();

        Log::info("Started next question", [
            'match_id' => $match->id,
            'question_index' => $nextIndex,
            'round' => $roundManager->getCurrentRound()
        ]);

        // Broadcast nueva pregunta
        event(new QuestionStartedEvent(
            $match,
            $nextQuestion['question'],
            $nextQuestion['options'],
            $roundManager->getCurrentRound(),
            $roundManager->getTotalRounds()
        ));
    }

    /**
     * Seleccionar preguntas según configuración.
     */
    private function selectQuestions(
        array $allQuestions,
        int $count,
        string $difficulty,
        string $category
    ): array {
        $selected = [];

        // Determinar categorías a usar
        $categories = $category === 'mixed'
            ? array_keys($allQuestions)
            : [$category];

        // Determinar dificultades a usar
        $difficulties = $difficulty === 'mixed'
            ? ['easy', 'medium', 'hard']
            : [$difficulty];

        // Recolectar todas las preguntas disponibles
        $available = [];
        foreach ($categories as $cat) {
            if (!isset($allQuestions[$cat])) continue;

            foreach ($difficulties as $diff) {
                if (!isset($allQuestions[$cat][$diff])) continue;

                foreach ($allQuestions[$cat][$diff] as $question) {
                    $available[] = array_merge($question, [
                        'category' => $cat,
                        'difficulty' => $diff
                    ]);
                }
            }
        }

        // Mezclar y tomar las primeras N preguntas
        shuffle($available);
        $selected = array_slice($available, 0, $count);

        return $selected;
    }

    /**
     * Verificar si hay un ganador.
     */
    public function checkWinCondition(GameMatch $match): ?Player
    {
        $gameState = $match->game_state;

        // Verificar si todas las preguntas han sido respondidas
        $roundManager = RoundManager::fromArray($gameState);

        if (!$roundManager->isGameComplete()) {
            return null; // Aún quedan preguntas
        }

        // Encontrar jugador con mayor puntuación
        $scores = $gameState['scores'];

        if (empty($scores)) {
            return null;
        }

        // Obtener el jugador con mayor puntuación
        arsort($scores);
        $winnerId = array_key_first($scores);

        return Player::find($winnerId);
    }

    /**
     * Obtener el estado del juego para un jugador específico.
     */
    public function getGameStateForPlayer(GameMatch $match, Player $player): array
    {
        $gameState = $match->game_state;

        // En Trivia, todos los jugadores ven el mismo estado
        // No hay información oculta como en Pictionary
        return [
            'phase' => $gameState['phase'],
            'current_question' => $gameState['current_question'] ?? null,
            'question_index' => $gameState['question_index'] ?? 0,
            'total_questions' => count($gameState['questions'] ?? []),
            'scores' => $gameState['scores'] ?? [],
            'has_answered' => isset($gameState['player_answers'][$player->id]) ?? false,
            'timer' => $gameState['timer'] ?? null,
        ];
    }

    /**
     * Manejar la desconexión de un jugador.
     */
    public function handlePlayerDisconnect(GameMatch $match, Player $player): void
    {
        Log::info("Player disconnected from Trivia", [
            'match_id' => $match->id,
            'player_id' => $player->id
        ]);

        // En Trivia, si un jugador se desconecta simplemente no responde
        // No afecta a los demás jugadores, el juego continúa
        // Su puntuación se mantiene como está
    }

    /**
     * Manejar la reconexión de un jugador.
     */
    public function handlePlayerReconnect(GameMatch $match, Player $player): void
    {
        Log::info("Player reconnected to Trivia", [
            'match_id' => $match->id,
            'player_id' => $player->id
        ]);

        // Al reconectarse, el jugador puede ver el estado actual
        // Si ya respondió la pregunta actual, no puede cambiar su respuesta
        // Si no ha respondido, puede hacerlo si aún hay tiempo
    }
}
