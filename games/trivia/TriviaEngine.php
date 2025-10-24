<?php

namespace Games\Trivia;

use App\Contracts\BaseGameEngine;
use App\Models\GameMatch;
use App\Models\Player;
use App\Services\Modules\ScoringSystem\ScoreManager;
use App\Services\Modules\TimerSystem\TimerService;
use App\Services\Modules\RoundSystem\RoundManager;
use App\Services\Modules\TurnSystem\TurnManager;
use App\Events\Game\RoundStartedEvent;
use App\Events\Game\RoundEndedEvent;
use App\Events\Game\PlayerActionEvent;
use App\Events\Game\TurnTimeoutEvent;
use Games\Trivia\Events\GameFinishedEvent;
use Games\Trivia\TriviaScoreCalculator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;

/**
 * Trivia Game Engine
 *
 * Juego de preguntas y respuestas donde todos los jugadores responden simultáneamente.
 *
 * ARQUITECTURA DESACOPLADA:
 * ========================
 * - Extiende BaseGameEngine para coordinación con módulos
 * - Implementa solo la lógica específica de Trivia
 * - BaseGameEngine maneja cuándo terminar rondas
 * - RoundManager gestiona el flujo de rondas
 *
 * Módulos utilizados:
 * - SessionManager: Identificación de jugadores
 * - RoundManager: Gestión de rondas (1 ronda = 1 pregunta)
 * - TurnManager: Modo simultáneo (todos juegan al mismo tiempo)
 * - ScoreManager: Puntos + bonus por velocidad
 * - TimerService: Tiempo límite por pregunta
 *
 * Flujo del juego:
 * 1. initialize(): Cargar preguntas, inicializar módulos
 * 2. processRoundAction(): Jugador responde pregunta
 * 3. endCurrentRound(): Calcular puntos y mostrar resultados
 * 4. startNewRound(): Siguiente pregunta
 * 5. finalize(): Mostrar ganador y ranking final
 */
class TriviaEngine extends BaseGameEngine
{
    /**
     * Constructor: Registrar listener para timeout.
     */
    public function __construct()
    {
        parent::__construct();

        // Listener: Cuando el turno expira (timeout)
        Event::listen(TurnTimeoutEvent::class, function ($event) {
            if ($event->match->room->game->slug === 'trivia') {
                $this->endCurrentRound($event->match);
            }
        });
    }


    /**
     * Inicializar el juego - SOLO guarda configuración.
     *
     * Este método se llama UNA VEZ al crear la partida.
     * NO resetea scores ni inicia el juego - eso lo hace startGame().
     */
    public function initialize(GameMatch $match): void
    {
        Log::info("Initializing Trivia configuration", ['match_id' => $match->id]);

        // Verificar que hay suficientes jugadores
        $players = $match->players;

        if ($players->count() < 1) {
            Log::error("Not enough players to start Trivia", [
                'match_id' => $match->id,
                'player_count' => $players->count()
            ]);
            throw new \Exception('Se requiere al menos 1 jugador para jugar Trivia');
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
        // GUARDAR CONFIGURACIÓN EN _config
        // ========================================================================
        $playerIds = $players->pluck('id')->toArray();

        $match->game_state = [
            '_config' => [
                'questions_per_game' => $questionsPerGame,
                'time_per_question' => $timePerQuestion,
                'difficulty' => $difficulty,
                'category' => $category,
                'questions' => $selectedQuestions,
                'player_ids' => $playerIds,
            ],
            // Estado vacío (se llenará en startGame)
            'phase' => 'waiting',
            'questions' => $selectedQuestions,
            'time_per_question' => $timePerQuestion,
        ];

        $match->save();

        // ========================================================================
        // INICIALIZAR MÓDULOS AUTOMÁTICAMENTE
        // ========================================================================
        // Lee config.json y crea solo los módulos que están enabled
        $this->initializeModules($match, [
            'round_system' => [
                'total_rounds' => count($selectedQuestions)
            ],
            'scoring_system' => [
                'calculator' => new TriviaScoreCalculator()
            ],
        ]);

        Log::info("Trivia configuration saved", [
            'match_id' => $match->id,
            'players' => count($playerIds),
            'questions' => count($selectedQuestions),
            'time_per_question' => $timePerQuestion
        ]);
    }

    /**
     * Hook específico de Trivia para iniciar el juego.
     *
     * IMPORTANTE: BaseGameEngine::startGame() ya reseteó los módulos automáticamente.
     * Este método solo debe setear el estado inicial específico de Trivia.
     */
    protected function onGameStart(GameMatch $match): void
    {
        Log::info("Trivia - onGameStart hook", ['match_id' => $match->id]);

        // 1. Leer configuración guardada
        $config = $match->game_state['_config'] ?? [];
        $questions = $config['questions'] ?? [];
        $timePerQuestion = $config['time_per_question'] ?? 15;

        if (empty($questions)) {
            throw new \RuntimeException("No questions found in configuration");
        }

        // 2. Obtener RoundManager (ya tiene TimerService conectado y timer iniciado)
        $roundManager = RoundManager::fromArray($match->game_state);

        // 3. Guardar el TimerService actualizado (con el timer iniciado)
        $timerService = $roundManager->getTurnManager()->getTimerService();
        if ($timerService) {
            $match->game_state = array_merge($match->game_state, $timerService->toArray());
        }

        // 4. Setear estado inicial específico de Trivia
        $firstQuestion = $questions[0];

        $match->game_state = array_merge($match->game_state, [
            'phase' => 'question',
            'current_question_index' => 0,
            'current_question' => $firstQuestion,
            'player_answers' => [],
            'question_start_time' => now()->timestamp,
            'question_results' => null,
        ]);

        $match->save();

        Log::info("Trivia - State set for first question", [
            'match_id' => $match->id,
            'question_index' => 0,
            'phase' => 'question',
            'question' => $firstQuestion['question']
        ]);

        // 5. Emitir evento genérico RoundStartedEvent con timing del turno
        $timingInfo = $roundManager->getTurnManager()->getTimingInfo();

        event(new RoundStartedEvent(
            match: $match,
            currentRound: $roundManager->getCurrentRound(),
            totalRounds: $roundManager->getTotalRounds(),
            phase: 'question',
            timing: $timingInfo
        ));

        Log::info("Trivia - RoundStartedEvent emitted for first question", [
            'match_id' => $match->id,
            'room_code' => $match->room->code,
            'round' => $roundManager->getCurrentRound()
        ]);
    }

    // ========================================================================
    // MÉTODOS IMPLEMENTADOS DE BaseGameEngine
    // ========================================================================

    /**
     * Sobrescribir processAction para controlar el flujo completo automáticamente.
     *
     * Flujo en Trivia:
     * 1. Jugador envía respuesta
     * 2. Backend procesa y decide si es correcta
     * 3. Si correcta → Terminar ronda + Iniciar siguiente ronda AUTOMÁTICAMENTE
     * 4. Si incorrecta → Bloquear jugador + Continuar con resto
     *
     * NO se usa frontend para iniciar siguientes rondas (evita race conditions).
     */
    public function processAction(GameMatch $match, Player $player, string $action, array $data): array
    {
        Log::info("[trivia] Processing action", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'action' => $action
        ]);

        // 1. Procesar acción (respuesta del jugador)
        $data['action'] = $action;
        $actionResult = $this->processRoundAction($match, $player, $data);

        // 2. Obtener RoundManager
        $roundManager = $this->getRoundManager($match);
        $turnMode = $roundManager->getTurnManager()->getMode();

        // 3. Obtener estrategia de finalización
        $strategy = $this->getEndRoundStrategy($turnMode);

        // 4. Consultar si debe terminar la ronda
        $roundStatus = $strategy->shouldEnd(
            $match,
            $actionResult,
            $roundManager,
            fn($match) => $this->getAllPlayerResults($match)
        );

        // 5. Si debe terminar la ronda (alguien respondió correctamente)
        if ($roundStatus['should_end']) {
            Log::info("[trivia] Round ending - player succeeded", [
                'match_id' => $match->id,
                'player_id' => $player->id,
                'reason' => $roundStatus['reason'] ?? 'player_succeeded'
            ]);

            // Finalizar ronda actual (emite RoundEndedEvent)
            // Frontend mostrará resultados y hará countdown local
            // Después del countdown, el primer frontend que envíe señal iniciará siguiente ronda
            $this->endCurrentRound($match);
        }

        // 6. Retornar resultado
        return array_merge($actionResult, [
            'round_status' => $roundStatus,
            'turn_mode' => $turnMode,
        ]);
    }

    /**
     * Procesar la acción de responder una pregunta.
     *
     * Este método NO decide si la ronda termina.
     * Solo procesa la respuesta y retorna el resultado.
     * BaseGameEngine se encargará de consultar a RoundManager.
     *
     * @param GameMatch $match
     * @param Player $player
     * @param array $data ['answer' => int]
     * @return array
     */
    protected function processRoundAction(GameMatch $match, Player $player, array $data): array
    {
        return $this->handleAnswer($match, $player, $data);
    }

    /**
     * Obtener todos los resultados de jugadores en la ronda actual.
     *
     * @param GameMatch $match
     * @return array [player_id => ['success' => bool, 'data' => array]]
     */
    protected function getAllPlayerResults(GameMatch $match): array
    {
        $gameState = $match->game_state;
        $playerResults = [];

        foreach ($gameState['player_answers'] ?? [] as $playerId => $answer) {
            $playerResults[$playerId] = [
                'success' => $answer['is_correct'] ?? false,
                'data' => $answer,
            ];
        }

        return $playerResults;
    }

    /**
     * Iniciar una nueva ronda (cargar siguiente pregunta).
     *
     * SOLO carga la siguiente pregunta. BaseGameEngine ya:
     * - Avanzó el RoundManager
     * - Verificó si el juego terminó
     * - Emitirá RoundStartedEvent después de esto
     *
     * @param GameMatch $match
     * @return void
     */
    protected function startNewRound(GameMatch $match): void
    {
        $gameState = $match->game_state;

        // Obtener índice de siguiente pregunta
        $nextIndex = ($gameState['current_question_index'] ?? -1) + 1;

        // Cargar nueva pregunta
        $nextQuestion = $gameState['questions'][$nextIndex];
        $gameState['current_question_index'] = $nextIndex;
        $gameState['current_question'] = $nextQuestion;
        $gameState['player_answers'] = [];
        $gameState['question_start_time'] = now()->timestamp;
        $gameState['phase'] = 'question';
        $gameState['question_results'] = [];

        $match->game_state = $gameState;
        $match->save();

        Log::info("Question loaded", [
            'match_id' => $match->id,
            'question_index' => $nextIndex,
            'question' => $nextQuestion['question']
        ]);
    }

    /**
     * Finalizar la ronda actual (pregunta actual).
     *
     * @param GameMatch $match
     * @return void
     */
    protected function endCurrentRound(GameMatch $match): void
    {
        $this->endQuestion($match);
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
        $scores = $this->getScores($gameState);
        $scoreManager = ScoreManager::fromArray(
            playerIds: array_keys($scores),
            data: $gameState,
            calculator: new TriviaScoreCalculator()
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

        $finalScores = $this->getScores($gameState);
        $statistics = [
            'total_questions' => $roundManager->getTotalRounds(),
            'questions_answered' => $roundManager->getCurrentRound() - 1,
            'players_count' => count($finalScores),
            'highest_score' => $winner ? $winner['score'] : 0,
            'average_score' => !empty($finalScores)
                ? round(array_sum($finalScores) / count($finalScores), 2)
                : 0,
        ];

        Log::info("Trivia game finalized", [
            'match_id' => $match->id,
            'winner' => $winner ? $winner['player_name'] : 'None',
            'statistics' => $statistics
        ]);

        // Broadcast game finished event
        event(new GameFinishedEvent($match, $ranking, $statistics));

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

        // Obtener la respuesta correcta
        $currentQuestion = $gameState['current_question'];
        $correctAnswer = $currentQuestion['correct'];
        $isCorrect = (int)$answerIndex === $correctAnswer;

        // Calcular tiempo transcurrido desde inicio de pregunta
        $secondsElapsed = now()->timestamp - $gameState['question_start_time'];

        // Guardar respuesta del jugador
        $gameState['player_answers'][$player->id] = [
            'answer' => (int)$answerIndex,
            'seconds_elapsed' => $secondsElapsed,
            'timestamp' => now()->timestamp,
            'is_correct' => $isCorrect,
        ];

        $match->game_state = $gameState;
        $match->save();

        Log::info("Player answered", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'answer' => $answerIndex,
            'is_correct' => $isCorrect,
            'seconds_elapsed' => $secondsElapsed
        ]);

        // Broadcast acción del jugador
        event(new PlayerActionEvent(
            match: $match,
            player: $player,
            actionType: 'answer',
            actionData: ['is_correct' => $isCorrect, 'answer' => $answerIndex],
            success: true
        ));

        // Retornar resultado simple
        // BaseGameEngine se encarga de coordinar con RoundManager
        return [
            'success' => true,
            'player_id' => $player->id,
            'is_correct' => $isCorrect,
            'message' => $isCorrect ? '¡Correcto!' : 'Incorrecto',
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
            playerIds: array_keys($gameState['scoring_system']['scores'] ?? []),
            data: $gameState,
            calculator: new TriviaScoreCalculator()
        );

        $questionResults = [];

        // Obtener todos los jugadores activos del RoundManager
        $roundManager = RoundManager::fromArray($gameState);
        $activePlayers = $roundManager->getActivePlayers();

        // Procesar resultados de TODOS los jugadores (respondieron o no)
        foreach ($activePlayers as $playerId) {
            $answerData = $gameState['player_answers'][$playerId] ?? null;

            if ($answerData) {
                // Jugador respondió
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
            } else {
                // Jugador NO respondió - penalizar con 0 puntos
                $questionResults[$playerId] = [
                    'correct' => false,
                    'points_earned' => 0,
                    'seconds_elapsed' => null,
                    'did_not_answer' => true
                ];
            }
        }

        // ✅ CORRECTO: Guardar ScoreManager usando el helper
        $this->saveScoreManager($match, $scoreManager);

        // Actualizar datos específicos de Trivia (no de módulos)
        $match->refresh();
        $gameState = $match->game_state;
        $gameState['question_results'] = $questionResults;
        $gameState['phase'] = 'results';

        $match->game_state = $gameState;
        $match->save();

        // Delegar a BaseGameEngine para coordinar
        // (emitirá RoundEndedEvent, avanzará ronda, llamará startNewRound, emitirá RoundStartedEvent)
        $this->completeRound($match, $questionResults);
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

        // Encontrar jugador con mayor puntuación usando helper
        $scores = $this->getScores($gameState);

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
            'scores' => $this->getScores($gameState),
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

    // ========================================================================
    // API PÚBLICA para Controllers
    // ========================================================================

    /**
     * Método público para que el Controller inicie la siguiente ronda.
     * Wrapper del método protegido startNewRound().
     *
     * @param GameMatch $match
     * @return void
     */
    public function advanceToNextRound(GameMatch $match): void
    {
        $this->startNewRound($match);
    }

    /**
     * Método público para que el Controller verifique si el juego terminó.
     * Wrapper del método protegido isGameComplete().
     *
     * @param GameMatch $match
     * @return bool
     */
    public function checkIfGameComplete(GameMatch $match): bool
    {
        return $this->isGameComplete($match);
    }

    /**
     * Obtener configuración del juego desde config.json.
     *
     * @return array
     */
    protected function getGameConfig(): array
    {
        $configPath = base_path('games/trivia/config.json');

        if (!file_exists($configPath)) {
            return [];
        }

        return json_decode(file_get_contents($configPath), true) ?? [];
    }
}
