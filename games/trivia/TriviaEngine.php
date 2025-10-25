<?php

namespace Games\Trivia;

use App\Contracts\BaseGameEngine;
use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

/**
 * Trivia Game Engine - Versión básica
 *
 * Solo implementa lo mínimo para verificar que el flujo híbrido funciona:
 * 1. initialize() - Guardar configuración mínima
 * 2. onGameStart() - Emitir evento para mostrar pantalla de inicio
 */
class TriviaEngine extends BaseGameEngine
{
    /**
     * Inicializar el juego - FASE 1 (LOBBY)
     *
     * Carga las preguntas desde questions.json y las mezcla aleatoriamente.
     */
    public function initialize(GameMatch $match): void
    {
        Log::info("[Trivia] Initializing - FASE 1", ['match_id' => $match->id]);

        // Cargar preguntas desde JSON
        $questionsPath = base_path('games/trivia/questions.json');
        $questionsData = json_decode(file_get_contents($questionsPath), true);

        // Mezclar preguntas aleatoriamente
        $questions = $questionsData['questions'];
        shuffle($questions);
        
        // Cargar config.json para obtener el valor por defecto
        $gameConfig = $this->getGameConfig();
        $defaultQuestionsPerGame = $gameConfig['customizableSettings']['questions_per_game']['default'] ?? 10;
        
        // Obtener configuración personalizada del usuario (o usar default del config)
        $gameSettings = $match->room->game_settings ?? [];
        $questionsPerGame = $gameSettings['questions_per_game'] ?? $defaultQuestionsPerGame;
        
        // Limitar preguntas según configuración del usuario
        $questions = array_slice($questions, 0, $questionsPerGame);
        
        Log::info("[Trivia] Questions configured", [
            'match_id' => $match->id,
            'total_available' => count($questionsData['questions']),
            'default_from_config' => $defaultQuestionsPerGame,
            'questions_per_game_setting' => $questionsPerGame,
            'questions_loaded' => count($questions)
        ]);

        // Crear mapeo user_id => player_id para el frontend
        $userToPlayerMap = [];
        foreach ($match->players as $player) {
            $userToPlayerMap[$player->user_id] = $player->id;
        }

        // Cargar config.json completo para timing y otras configuraciones
        $gameConfig = $this->getGameConfig();
        
        // Guardar configuración con preguntas
        $match->game_state = [
            '_config' => [
                'game' => 'trivia',
                'initialized_at' => now()->toDateTimeString(),
                'categories' => $questionsData['categories'],
                'user_to_player_map' => $userToPlayerMap, // Mapeo para el frontend
                'timing' => $gameConfig['timing'] ?? null, // Timing para RoundManager
                'modules' => $gameConfig['modules'] ?? [], // Configuración de módulos
            ],
            'phase' => 'waiting',
            'questions' => $questions,
            'current_question' => null,
        ];

        $match->save();

        // Inicializar módulos automáticamente desde config.json
        $scoringConfig = $questionsData['scoring'] ?? [];
        $this->initializeModules($match, [
            'scoring_system' => [
                'calculator' => new TriviaScoreCalculator($scoringConfig)
            ],
            'round_system' => [
                'total_rounds' => count($questions) // Número real de preguntas cargadas
            ]
        ]);

        // Inicializar PlayerStateManager con los IDs de jugadores
        $playerIds = $match->players->pluck('id')->toArray();
        $playerState = new \App\Services\Modules\PlayerStateSystem\PlayerStateManager(
            playerIds: $playerIds
        );
        $this->savePlayerStateManager($match, $playerState);

        Log::info("[Trivia] Questions loaded and shuffled", [
            'match_id' => $match->id,
            'total_questions' => count($questions),
            'total_players' => count($playerIds)
        ]);
    }

    /**
     * Hook cuando el juego empieza - FASE 3 (POST-COUNTDOWN)
     *
     * BaseGameEngine ya resetó los módulos.
     * Usamos handleNewRound() para iniciar la primera ronda siguiendo el flujo estándar.
     */
    protected function onGameStart(GameMatch $match): void
    {
        Log::info("[Trivia] Game starting - FASE 3", ['match_id' => $match->id]);

        // Setear fase inicial
        $match->game_state = array_merge($match->game_state, [
            'phase' => 'playing',
            'started_at' => now()->toDateTimeString(),
        ]);

        $match->save();

        // Usar handleNewRound() para iniciar la primera ronda
        // advanceRound: false porque ya estamos en ronda 1 (resetModules ya lo hizo)
        // Esto llama a startNewRound() + emite RoundStartedEvent automáticamente
        $this->handleNewRound($match, advanceRound: false);

        Log::info("[Trivia] First round started via handleNewRound()", [
            'match_id' => $match->id,
            'room_code' => $match->room->code
        ]);
    }

    /**
     * Procesar acción de ronda - Responder pregunta.
     *
     * Flujo:
     * 1. Verificar que el jugador no esté bloqueado
     * 2. Verificar la respuesta
     * 3. Si es correcta:
     *    - Sumar puntos
     *    - Marcar que la ronda debe terminar (alguien acertó)
     * 4. Si es incorrecta:
     *    - Bloquear al jugador
     *    - Verificar si quedan jugadores sin bloquear
     *    - Si no quedan, marcar que la ronda debe terminar
     *
     * @param GameMatch $match
     * @param Player $player
     * @param array $data ['answer_index' => int]
     * @return array ['success' => bool, 'correct' => bool, 'force_end' => bool]
     */
    protected function processRoundAction(GameMatch $match, Player $player, array $data): array
    {
        Log::info("[Trivia] Processing answer", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'answer_index' => $data['answer_index'] ?? null
        ]);

        // 1. Validar que se envió una respuesta
        if (!isset($data['answer_index'])) {
            return [
                'success' => false,
                'message' => 'No se envió una respuesta',
            ];
        }

        $answerIndex = (int) $data['answer_index'];

        // 2. Verificar que el jugador no esté bloqueado
        $playerState = $this->getPlayerStateManager($match);

        if ($playerState->isPlayerLocked($player->id)) {
            return [
                'success' => false,
                'message' => 'Ya respondiste esta pregunta',
            ];
        }

        // 3. Obtener la pregunta actual
        $currentQuestion = $this->getCurrentQuestion($match);

        if (!$currentQuestion) {
            return [
                'success' => false,
                'message' => 'No hay pregunta actual',
            ];
        }

        // 4. Verificar la respuesta
        $isCorrect = ($answerIndex === $currentQuestion['correct_answer']);

        // 5. Registrar la acción del jugador
        $playerState->setPlayerAction($player->id, [
            'type' => 'answer',
            'answer_index' => $answerIndex,
            'is_correct' => $isCorrect,
            'timestamp' => now()->toDateTimeString(),
        ]);

        // 6. Procesar resultado según si es correcta o no
        $resultData = [];
        $forceEnd = false;

        if ($isCorrect) {
            // ✅ RESPUESTA CORRECTA
            Log::info("[Trivia] Correct answer!", [
                'match_id' => $match->id,
                'player_id' => $player->id,
                'question_id' => $currentQuestion['id']
            ]);

            // Sumar puntos (más puntos según dificultad)
            $points = $this->calculatePoints($currentQuestion['difficulty']);
            
            // Obtener configuración de scoring desde el config.json
            $gameConfig = $this->getGameConfig();
            $scoringConfig = $gameConfig['scoring'] ?? [];
            $calculator = new TriviaScoreCalculator($scoringConfig);
            
            $this->awardPoints($match, $player->id, 'correct_answer', ['points' => $points], $calculator);

            $resultData = [
                'is_correct' => true,
                'points' => $points,
                'answer_index' => $answerIndex,
            ];

            // REGLA DE TRIVIA: Alguien acertó → termina la ronda
            $forceEnd = true;
            $endReason = 'player_answered_correctly';

        } else {
            // ❌ RESPUESTA INCORRECTA
            Log::info("[Trivia] Incorrect answer", [
                'match_id' => $match->id,
                'player_id' => $player->id,
                'question_id' => $currentQuestion['id'],
                'answer_given' => $answerIndex,
                'correct_answer' => $currentQuestion['correct_answer']
            ]);

            $resultData = [
                'is_correct' => false,
                'answer_index' => $answerIndex,
                'correct_answer' => $currentQuestion['correct_answer'],
            ];

            // NO forzar fin de ronda aún
            // Esperamos a verificar si todos ya respondieron
            $forceEnd = false;
            $endReason = null;
        }

        // 7. Bloquear jugador (en ambos casos)
        // El PlayerStateManager emite evento Y nos dice si todos están bloqueados
        $lockResult = $playerState->lockPlayer($player->id, $match, $player, $resultData);
        $this->savePlayerStateManager($match, $playerState);

        // 8. Si la respuesta fue incorrecta, verificar si todos ya respondieron
        if (!$isCorrect && $lockResult['all_players_locked']) {
            // REGLA DE TRIVIA: Todos respondieron incorrectamente → termina la ronda
            $forceEnd = true;
            $endReason = 'all_players_answered_incorrectly';
        }

        // 9. Retornar resultado
        return array_merge([
            'success' => true,
            'force_end' => $forceEnd,
            'end_reason' => $endReason,
            'player_id' => $player->id,
        ], $resultData);
    }

    /**
     * Calcular puntos según dificultad de la pregunta.
     *
     * @param string $difficulty
     * @return int
     */
    private function calculatePoints(string $difficulty): int
    {
        return match($difficulty) {
            'easy' => 10,
            'medium' => 20,
            'hard' => 30,
            default => 10,
        };
    }

    /**
     * Iniciar nueva ronda - Cargar siguiente pregunta.
     *
     * Este método se llama desde BaseGameEngine::handleNewRound()
     * DESPUÉS de que RoundManager haya avanzado la ronda y reseteado el timer.
     */
    protected function startNewRound(GameMatch $match): void
    {
        Log::info("[Trivia] Starting new round", ['match_id' => $match->id]);

        // 1. Desbloquear jugadores (via PlayerStateManager)
        // El reset emitirá automáticamente PlayersUnlockedEvent
        $playerState = $this->getPlayerStateManager($match);
        $playerState->reset($match);  // ← Resetea locks, actions, states, etc. y emite evento
        $this->savePlayerStateManager($match, $playerState);

        // 2. Cargar siguiente pregunta
        $question = $this->loadNextQuestion($match);

        Log::info("[Trivia] Question loaded and players unlocked", [
            'match_id' => $match->id,
            'question_id' => $question['id'],
            'question' => $question['question']
        ]);

        // Emitir evento específico de Trivia (opcional)
        // event(new QuestionStartedEvent($match, $question));
    }

    // ========================================================================
    // GESTIÓN DE PREGUNTAS
    // ========================================================================

    /**
     * Cargar la siguiente pregunta basándose en la ronda actual.
     *
     * @param GameMatch $match
     * @return array Pregunta cargada
     * @throws \RuntimeException Si no hay pregunta para la ronda actual
     */
    private function loadNextQuestion(GameMatch $match): array
    {
        $roundManager = $this->getRoundManager($match);
        $currentRound = $roundManager->getCurrentRound();

        $question = $this->getQuestionByRound($match, $currentRound);

        $this->setCurrentQuestion($match, $question);

        return $question;
    }

    /**
     * Obtener pregunta por número de ronda.
     *
     * @param GameMatch $match
     * @param int $roundNumber Número de ronda (1-based)
     * @return array Pregunta
     * @throws \RuntimeException Si no existe pregunta para esa ronda
     */
    private function getQuestionByRound(GameMatch $match, int $roundNumber): array
    {
        $questions = $match->game_state['questions'] ?? [];
        $questionIndex = $roundNumber - 1; // Convertir a índice 0-based

        if (!isset($questions[$questionIndex])) {
            throw new \RuntimeException("No hay pregunta para la ronda {$roundNumber}");
        }

        return $questions[$questionIndex];
    }

    /**
     * Establecer la pregunta actual en el estado del juego.
     *
     * @param GameMatch $match
     * @param array $question
     * @return void
     */
    private function setCurrentQuestion(GameMatch $match, array $question): void
    {
        $gameState = $match->game_state;
        $gameState['current_question'] = $question;
        $match->game_state = $gameState;
        $match->save();
    }

    /**
     * Obtener la pregunta actual.
     *
     * @param GameMatch $match
     * @return array|null Pregunta actual o null si no hay ninguna
     */
    private function getCurrentQuestion(GameMatch $match): ?array
    {
        return $match->game_state['current_question'] ?? null;
    }


    /**
     * Finalizar ronda actual.
     *
     * Obtiene los resultados de la ronda y llama a completeRound()
     * que se encarga de emitir eventos y avanzar a la siguiente ronda.
     */
    public function endCurrentRound(GameMatch $match): void
    {
        Log::info("[Trivia] Ending current round", ['match_id' => $match->id]);

        // Obtener resultados de todos los jugadores
        $results = $this->getAllPlayerResults($match);

        // Llamar a completeRound() que:
        // 1. Emite RoundEndedEvent
        // 2. Avanza RoundManager
        // 3. Verifica si el juego terminó
        // 4. Si no terminó, llama a startNewRound() y emite RoundStartedEvent
        $this->completeRound($match, $results);

        Log::info("[Trivia] Round completed", [
            'match_id' => $match->id,
            'results' => $results
        ]);
    }

    /**
     * Analizar el estado actual de la ronda según las reglas de Trivia.
     *
     * REGLAS DE TRIVIA:
     * 1. Si alguien acertó → ronda debe terminar
     * 2. Si todos respondieron incorrectamente → ronda debe terminar
     * 3. Si hay jugadores sin responder → ronda continúa
     *
     * NOTA: Este método es para análisis/debugging. La decisión real
     * de terminar ronda se toma en processRoundAction() con force_end.
     *
     * @param GameMatch $match
     * @return array ['should_end' => bool, 'reason' => string|null]
     */
    protected function checkRoundState(GameMatch $match): array
    {
        $playerState = $this->getPlayerStateManager($match);
        $allActions = $playerState->getAllActions();
        
        Log::info("[Trivia] Checking round state", [
            'match_id' => $match->id,
            'total_actions' => count($allActions),
            'total_players' => $playerState->getTotalPlayers(),
            'locked_players' => count($playerState->getLockedPlayers())
        ]);
        
        // REGLA 1: Verificar si alguien acertó
        foreach ($allActions as $playerId => $action) {
            if ($action['type'] === 'answer' && $action['is_correct']) {
                Log::info("[Trivia] Round state check: Player answered correctly", [
                    'match_id' => $match->id,
                    'player_id' => $playerId
                ]);
                
                return [
                    'should_end' => true,
                    'reason' => 'player_answered_correctly'
                ];
            }
        }
        
        // REGLA 2: Verificar si todos respondieron incorrectamente
        $allLocked = $playerState->areAllPlayersLocked();
        
        Log::info("[Trivia] Round state check - lock status", [
            'match_id' => $match->id,
            'all_locked' => $allLocked
        ]);
        
        if ($allLocked) {
            Log::info("[Trivia] Round state check: All players answered incorrectly", [
                'match_id' => $match->id
            ]);
            
            return [
                'should_end' => true,
                'reason' => 'all_players_answered_incorrectly'
            ];
        }
        
        // REGLA 3: Aún hay jugadores sin responder
        Log::info("[Trivia] Round state check: Waiting for more players");
        
        return [
            'should_end' => false,
            'reason' => null
        ];
    }

    /**
     * Obtener resultados de todos los jugadores en la ronda actual.
     *
     * Retorna quién respondió, si acertó, y cuántos puntos ganó.
     *
     * @param GameMatch $match
     * @return array
     */
    protected function getAllPlayerResults(GameMatch $match): array
    {
        $playerState = $this->getPlayerStateManager($match);
        $allActions = $playerState->getAllActions();
        $currentQuestion = $this->getCurrentQuestion($match);

        $playerResults = [];
        $winnerId = null;

        foreach ($allActions as $playerId => $action) {
            if ($action['type'] === 'answer') {
                $playerResults[] = [
                    'player_id' => $playerId,
                    'answer_index' => $action['answer_index'],
                    'is_correct' => $action['is_correct'],
                    'points' => $action['is_correct']
                        ? $this->calculatePoints($currentQuestion['difficulty'] ?? 'easy')
                        : 0,
                    'timestamp' => $action['timestamp'],
                ];

                // Guardar el primer jugador que acertó
                if ($action['is_correct'] && $winnerId === null) {
                    $winnerId = $playerId;
                }
            }
        }

        return [
            'players' => $playerResults,
            'question' => [
                'id' => $currentQuestion['id'] ?? null,
                'question' => $currentQuestion['question'] ?? null,
                'correct_answer' => $currentQuestion['correct_answer'] ?? null,
                'options' => $currentQuestion['options'] ?? [],
            ],
            'winner_id' => $winnerId, // null si nadie acertó
        ];
    }

    /**
     * Verificar condición de victoria.
     * Por ahora siempre retorna null (no hay ganador).
     */
    public function checkWinCondition(GameMatch $match): ?Player
    {
        return null;
    }

    /**
     * Obtener estado del juego para un jugador.
     */
    public function getGameStateForPlayer(GameMatch $match, Player $player): array
    {
        return [
            'phase' => $match->game_state['phase'] ?? 'unknown',
            'message' => 'El juego ha empezado',
        ];
    }

    /**
     * Manejar desconexión de jugador.
     */
    public function handlePlayerDisconnect(GameMatch $match, Player $player): void
    {
        Log::info("[Trivia] Player disconnected", [
            'match_id' => $match->id,
            'player_id' => $player->id
        ]);
    }

    /**
     * Manejar reconexión de jugador.
     */
    public function handlePlayerReconnect(GameMatch $match, Player $player): void
    {
        Log::info("[Trivia] Player reconnected", [
            'match_id' => $match->id,
            'player_id' => $player->id
        ]);
    }

    /**
     * Finalizar el juego.
     */
    public function finalize(GameMatch $match): array
    {
        Log::info("[Trivia] Finalizing game", ['match_id' => $match->id]);

        // 1. Obtener scores finales
        $gameConfig = $this->getGameConfig();
        $scoringConfig = $gameConfig['scoring'] ?? [];
        $calculator = new TriviaScoreCalculator($scoringConfig);
        
        $scoreManager = $this->getScoreManager($match, $calculator);
        $scores = $scoreManager->getScores();
        
        // 2. Crear ranking ordenado por puntos
        arsort($scores);
        $ranking = [];
        $position = 1;
        
        foreach ($scores as $playerId => $score) {
            $ranking[] = [
                'position' => $position++,
                'player_id' => $playerId,
                'score' => $score,
            ];
        }
        
        // 3. Determinar ganador (el primero en ranking)
        $winner = !empty($ranking) ? $ranking[0]['player_id'] : null;
        
        // 4. Marcar partida como terminada
        $match->game_state = array_merge($match->game_state, [
            'phase' => 'finished',
            'finished_at' => now()->toDateTimeString(),
            'final_scores' => $scores,
            'ranking' => $ranking,
            'winner' => $winner,
        ]);
        
        $match->save();
        
        // 5. Emitir evento de juego terminado
        event(new \App\Events\Game\GameEndedEvent(
            match: $match,
            winner: $winner,
            ranking: $ranking,
            scores: $scores
        ));
        
        Log::info("[Trivia] Game finalized", [
            'match_id' => $match->id,
            'winner' => $winner,
            'total_players' => count($ranking)
        ]);

        return [
            'winner' => $winner,
            'ranking' => $ranking,
            'statistics' => [
                'total_rounds' => $this->getRoundManager($match)->getCurrentRound(),
                'final_scores' => $scores,
            ],
        ];
    }

    /**
     * Obtener configuración del juego desde config.json
     *
     * @return array
     */
    protected function getGameConfig(): array
    {
        static $config = null;
        
        if ($config === null) {
            $configPath = base_path('games/trivia/config.json');
            if (file_exists($configPath)) {
                $config = json_decode(file_get_contents($configPath), true);
            } else {
                $config = [];
            }
        }
        
        return $config;
    }
}
