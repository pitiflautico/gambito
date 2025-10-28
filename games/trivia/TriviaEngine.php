<?php

namespace Games\Trivia;

use App\Contracts\BaseGameEngine;
use App\Models\GameMatch;
use App\Models\Player;
use App\Events\Game\PhaseChangedEvent;
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
     * Score calculator instance (reutilizado para evitar instanciación repetida)
     */
    protected TriviaScoreCalculator $scoreCalculator;

    public function __construct()
    {
        // Cargar configuración del juego para scoring
        $questionsPath = base_path('games/trivia/questions.json');
        $questionsData = json_decode(file_get_contents($questionsPath), true);
        $scoringConfig = $questionsData['scoring'] ?? [];

        // Inicializar calculator con la configuración
        $this->scoreCalculator = new TriviaScoreCalculator($scoringConfig);
    }

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

        // ✅ NUEVO (Fase 1): Cachear players en _config (1 query, 1 sola vez)
        // Esto evita queries durante el juego
        $this->cachePlayersInState($match);

        // Inicializar módulos automáticamente desde config.json
        $this->initializeModules($match, [
            'scoring_system' => [
                'calculator' => $this->scoreCalculator
            ],
            'round_system' => [
                'total_rounds' => count($questions) // Número real de preguntas cargadas
            ]
        ]);

        // Inicializar PlayerManager (unificado: scores + state)
        $playerIds = $match->players->pluck('id')->toArray();
        $playerManager = new \App\Services\Modules\PlayerSystem\PlayerManager(
            $playerIds,
            $this->scoreCalculator,
            [
                'available_roles' => [], // Trivia no usa roles
                'allow_multiple_persistent_roles' => false,
                'track_score_history' => false,
            ]
        );
        $this->savePlayerManager($match, $playerManager);

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
        $playerManager =$this->getPlayerManager($match, $this->scoreCalculator);

        if ($playerManager->isPlayerLocked($player->id)) {
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
        $playerManager->setPlayerAction($player->id, [
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

            // Preparar context con dificultad y timing (para speed bonus)
            $context = [
                'difficulty' => $currentQuestion['difficulty'] ?? 'medium',
            ];

            // Si hay timer de ronda, calcular elapsed time para speed bonus
            $gameConfig = $this->getGameConfig();
            $timerDuration = $gameConfig['modules']['timer_system']['round_duration'] ?? null;
            if ($timerDuration) {
                try {
                    $elapsedTime = $this->getElapsedTime($match, 'round');
                    $context['time_taken'] = $elapsedTime;
                    $context['time_limit'] = $timerDuration;

                    Log::info("[Trivia] Speed bonus calculation", [
                        'elapsed' => $elapsedTime,
                        'limit' => $timerDuration
                    ]);
                } catch (\Exception $e) {
                    // Timer no existe o expiró - no hay speed bonus
                    Log::debug("[Trivia] No timer available for speed bonus");
                }
            }

            // Sumar puntos (PlayerManager emite evento automáticamente)
            $totalPoints = $playerManager->awardPoints($player->id, 'correct_answer', $context, $match);

            $resultData = [
                'is_correct' => true,
                'points' => $totalPoints,
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
        $lockResult = $playerManager->lockPlayer($player->id, $match, $player, $resultData);
        $this->savePlayerManager($match, $playerManager);

        // 8. Si la respuesta fue incorrecta, verificar si todos ya respondieron
        if (!$isCorrect) {
            // Verificar si todos los jugadores están bloqueados
            $lockedPlayers = $playerManager->getLockedPlayers();
            $totalPlayers = count($match->players);

            if (count($lockedPlayers) === $totalPlayers) {
                // REGLA DE TRIVIA: Todos respondieron incorrectamente → termina la ronda
                $forceEnd = true;
                $endReason = 'all_players_answered_incorrectly';

                Log::info("[Trivia] All players answered incorrectly - ending round", [
                    'match_id' => $match->id,
                    'total_players' => $totalPlayers,
                    'locked_players' => count($lockedPlayers),
                ]);
            }
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
     * Hook: Preparar datos para la nueva ronda (cargar pregunta).
     *
     * BaseGameEngine ya resetó PlayerManager y está listo para la nueva ronda.
     * Aquí solo cargamos la pregunta específica de Trivia.
     *
     * @param GameMatch $match
     * @return void
     */
    protected function onRoundStarting(GameMatch $match): void
    {
        Log::info("[Trivia] Preparing round data - loading question", ['match_id' => $match->id]);

        // Solo lógica específica de Trivia: cargar siguiente pregunta
        $question = $this->loadNextQuestion($match);

        Log::info("[Trivia] Question loaded for new round", [
            'match_id' => $match->id,
            'question_id' => $question['id'],
            'question' => $question['question']
        ]);
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
     * Filtrar game_state para remover información sensible antes de broadcast.
     *
     * En Trivia necesitamos:
     * 1. Remover TODAS las preguntas (solo enviar la actual)
     * 2. Remover la respuesta correcta de la pregunta actual
     * 3. Mantener solo la info necesaria para el frontend
     *
     * @param array $gameState
     * @param \App\Models\GameMatch $match
     * @return array
     */
    protected function filterGameStateForBroadcast(array $gameState, \App\Models\GameMatch $match): array
    {
        $filtered = $gameState;

        // 1. REMOVER TODAS LAS PREGUNTAS (payload muy grande para WebSocket)
        // El frontend no necesita ver todas las preguntas, solo la actual
        unset($filtered['questions']);

        // 2. Remover la respuesta correcta de la pregunta actual
        if (isset($filtered['current_question']['correct_answer'])) {
            unset($filtered['current_question']['correct_answer']);
        }

        // 3. Remover otros datos grandes innecesarios
        if (isset($filtered['_config']['categories'])) {
            unset($filtered['_config']['categories']);
        }

        return $filtered;
    }

    /**
     * Obtener resultados de la ronda actual (implementación de Trivia).
     *
     * Retorna quién respondió, si acertó, y cuántos puntos ganó.
     * BaseGameEngine::endCurrentRound() llama a este método automáticamente.
     *
     * @param GameMatch $match
     * @return array Resultados de la ronda
     */
    protected function getRoundResults(GameMatch $match): array
    {
        $playerManager =$this->getPlayerManager($match, $this->scoreCalculator);
        $allActions = $playerManager->getAllActions();
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

    // ========================================================================
    // PHASE MANAGEMENT (usando RoundManager->getTurnManager())
    // ========================================================================

    /**
     * Override: No timing en RoundStartedEvent porque usamos PhaseManager.
     *
     * Trivia usa PhaseManager (vía RoundManager) con una única fase 'main' por ronda.
     * La fase tiene su propio timer emitido via PhaseChangedEvent.
     * RoundStartedEvent no debe incluir timing para evitar conflictos.
     */
    protected function getRoundStartTiming(GameMatch $match): ?array
    {
        return null;  // No timing en RoundStartedEvent - usamos PhaseChangedEvent
    }

    /**
     * Hook: Ejecutado DESPUÉS de emitir RoundStartedEvent.
     *
     * Emitimos PhaseChangedEvent para la fase única (main) con timing.
     * Esto permite que el frontend primero procese RoundStartedEvent (actualizar UI, pregunta)
     * y LUEGO reciba PhaseChangedEvent para iniciar el timer.
     */
    protected function onRoundStarted(GameMatch $match, int $currentRound, int $totalRounds): void
    {
        $roundManager = $this->getRoundManager($match);
        $phaseManager = $roundManager->getTurnManager(); // PhaseManager es el TurnManager

        if (!$phaseManager) {
            Log::error("[Trivia] PhaseManager NOT FOUND in RoundManager", [
                'match_id' => $match->id
            ]);
            return;
        }

        $currentPhase = $phaseManager->getCurrentPhaseName();
        $timingInfo = $phaseManager->getTimingInfo();

        $timing = [
            'server_time' => now()->timestamp,
            'duration' => $timingInfo['delay'] ?? 0
        ];

        Log::info("[Trivia] Emitting PhaseChangedEvent after RoundStarted", [
            'match_id' => $match->id,
            'phase' => $currentPhase,
            'duration' => $timing['duration']
        ]);

        event(new PhaseChangedEvent(
            match: $match,
            newPhase: $currentPhase,
            previousPhase: '',  // Primera fase, no hay anterior
            additionalData: $timing
        ));
    }

    // ========================================================================
    // MÉTODOS HEREDADOS
    // ========================================================================

    // getGameConfig() y getFinalScores() ahora se heredan de BaseGameEngine
    // (implementación común para todos los juegos)

    // handlePlayerDisconnect() y handlePlayerReconnect() también se heredan
    // de BaseGameEngine (comportamiento por defecto: pausar/reanudar juego)
}
