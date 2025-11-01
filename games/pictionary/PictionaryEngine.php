<?php

namespace Games\Pictionary;

use App\Contracts\BaseGameEngine;
use App\Events\Game\PhaseChangedEvent;
use App\Events\Pictionary\WordRevealedEvent;
use App\Models\GameMatch;
use App\Models\Player;
use App\Services\Modules\RoundSystem\RoundManager;
use Illuminate\Support\Facades\Log;

/**
 * Pictionary Game Engine
 *
 * Juego de dibujo y adivinanzas donde un jugador dibuja
 * mientras los demás intentan adivinar la palabra.
 */
class PictionaryEngine extends BaseGameEngine
{
    /**
     * Score calculator instance (reutilizado para evitar instanciación repetida)
     */
    protected PictionaryScoreCalculator $scoreCalculator;

    public function __construct()
    {
        // Cargar configuración del juego
        $gameConfig = $this->getGameConfig();
        $scoringConfig = $gameConfig['scoring'] ?? [];

        // Inicializar calculator con la configuración
        $this->scoreCalculator = new PictionaryScoreCalculator($scoringConfig);
    }

    /**
     * Inicializar el juego - FASE 1 (LOBBY)
     *
     * Carga el banco de palabras y configura la rotación de drawers.
     */
    public function initialize(GameMatch $match): void
    {
        Log::info("[Pictionary] Initializing - FASE 1", ['match_id' => $match->id]);

        // Cargar palabras desde words.json
        $wordsPath = base_path('games/pictionary/words.json');
        $wordsData = json_decode(file_get_contents($wordsPath), true);

        // Cargar config.json para obtener configuraciones
        $gameConfig = $this->getGameConfig();
        $defaultRounds = $gameConfig['customizableSettings']['rounds']['default'] ?? 10;

        // Obtener configuración personalizada del usuario
        $gameSettings = $match->room->game_settings ?? [];
        $totalRounds = $gameSettings['rounds'] ?? $defaultRounds;
        $difficulty = $gameSettings['difficulty'] ?? 'medium';

        // Seleccionar palabras según dificultad y número de rondas
        $selectedWords = $this->selectWords($wordsData, $totalRounds, $difficulty);

        Log::info("[Pictionary] Words configured", [
            'match_id' => $match->id,
            'total_rounds' => $totalRounds,
            'difficulty' => $difficulty,
            'words_selected' => count($selectedWords)
        ]);

        // Crear mapeo user_id => player_id para el frontend
        $userToPlayerMap = [];
        foreach ($match->players as $player) {
            $userToPlayerMap[$player->user_id] = $player->id;
        }

        // Guardar configuración inicial
        $match->game_state = [
            '_config' => [
                'game' => 'pictionary',
                'initialized_at' => now()->toDateTimeString(),
                'user_to_player_map' => $userToPlayerMap,
                'timing' => $gameConfig['timing'] ?? null,
                'modules' => $gameConfig['modules'] ?? [],
                'canvas' => $gameConfig['canvas'] ?? [],
            ],
            'phase' => 'waiting',
            'words' => $selectedWords,
            'current_word' => null,
            'drawer_rotation' => [], // Se inicializará en onGameStart
            'canvas_data' => [], // Strokes del dibujo actual
        ];

        $match->save();

        // Inicializar módulos automáticamente desde config.json
        $this->initializeModules($match, [
            'scoring_system' => [
                'calculator' => $this->scoreCalculator
            ],
            'round_system' => [
                'total_rounds' => count($selectedWords)
            ]
        ]);

        // Inicializar PlayerManager (unificado: scores + state + roles)
        $playerIds = $match->players->pluck('id')->toArray();
        $availableRoles = $gameConfig['modules']['roles_system']['roles'] ?? ['drawer', 'guesser', 'viewer'];

        $playerManager = new \App\Services\Modules\PlayerSystem\PlayerManager(
            $playerIds,
            $this->scoreCalculator,
            [
                'available_roles' => $availableRoles,
                'allow_multiple_persistent_roles' => false, // Un jugador solo puede tener un rol a la vez
                'track_score_history' => false,
            ]
        );
        $this->savePlayerManager($match, $playerManager);

        Log::info("[Pictionary] Initialized successfully", [
            'match_id' => $match->id,
            'total_players' => count($playerIds),
            'total_rounds' => $totalRounds
        ]);
    }

    /**
     * Hook cuando el juego empieza.
     */
    protected function onGameStart(GameMatch $match): void
    {
        Log::info('[Pictionary] onGameStart', ['match_id' => $match->id]);

        // Establecer rotación de drawers (todos los jugadores)
        $playerIds = $match->players->pluck('id')->toArray();
        shuffle($playerIds); // Orden aleatorio

        $match->game_state = array_merge($match->game_state, [
            'phase' => 'playing',
            'started_at' => now()->toDateTimeString(),
            'drawer_rotation' => $playerIds,
            'current_drawer_index' => 0,
        ]);
        $match->save();

        // Note: UI setup will happen in onRoundStarting() which is called by handleNewRound()
        $this->handleNewRound($match, advanceRound: false);
    }


    /**
     * Procesar acción de ronda.
     *
     * RETORNO:
     * [
     *   'success' => bool,
     *   'player_id' => int,
     *   'data' => mixed,
     *   'force_end' => bool,         // ¿Forzar fin de ronda?
     *   'end_reason' => string|null  // Razón del fin
     * ]
     */
    protected function processRoundAction(GameMatch $match, Player $player, array $data): array
    {
        $action = $data['action'] ?? null;

        if ($action === 'claim_answer') {
            return $this->processClaimAnswer($match, $player, $data);
        } elseif ($action === 'validate_claim') {
            $result = $this->processValidateClaim($match, $player, $data);
            // Si force_end es true, terminar la ronda
            if (($result['force_end'] ?? false) === true) {
                $this->endCurrentRound($match);
            }
            return $result;
        } elseif ($action === 'draw_stroke') {
            return $this->processDrawStroke($match, $player, $data);
        } elseif ($action === 'clear_canvas') {
            return $this->processClearCanvas($match, $player);
        }

        return [
            'success' => false,
            'message' => 'Acción desconocida',
            'force_end' => false,
        ];
    }

    /**
     * Procesar claim de respuesta (jugador dice "¡Lo sé!")
     *
     * 1. Verificar que el jugador sea guesser
     * 2. Verificar que no esté bloqueado
     * 3. Marcar al jugador como "claiming" en player_state
     * 4. Emitir evento AnswerClaimedEvent
     */
    private function processClaimAnswer(GameMatch $match, Player $player, array $data): array
    {
        Log::info("[Pictionary] Processing claim_answer", [
            'match_id' => $match->id,
            'player_id' => $player->id,
        ]);

        $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);

        // 1. Verificar que el jugador sea guesser
        $guessers = $playerManager->getPlayersWithRoundRole('guesser');
        $drawers = $playerManager->getPlayersWithRoundRole('drawer');

        // Extraer round roles
        $playerData = $playerManager->toArray()['player_system']['players'] ?? [];
        $allRoundRoles = [];
        foreach ($playerData as $playerId => $data) {
            if (isset($data['round_role'])) {
                $allRoundRoles[$playerId] = $data['round_role'];
            }
        }

        Log::info("[Pictionary] Role validation for claim", [
            'player_id' => $player->id,
            'guessers' => $guessers,
            'drawers' => $drawers,
            'all_round_roles' => $allRoundRoles,
            'is_guesser' => in_array($player->id, $guessers),
        ]);

        if (!in_array($player->id, $guessers)) {
            return [
                'success' => false,
                'message' => 'Solo los guessers pueden adivinar',
            ];
        }

        // 2. Verificar que no esté bloqueado
        if ($playerManager->isPlayerLocked($player->id)) {
            return [
                'success' => false,
                'message' => 'Ya has sido validado en esta ronda',
            ];
        }

        // 3. Marcar al jugador como "claiming" (opcional, para tracking)
        $playerManager->setPlayerAction($player->id, [
            'type' => 'claim',
            'timestamp' => now()->toDateTimeString(),
        ]);

        $this->savePlayerManager($match, $playerManager);

        // 4. Emitir evento AnswerClaimedEvent
        event(new \App\Events\Pictionary\AnswerClaimedEvent($match, $player));

        Log::info("[Pictionary] Claim broadcast", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'player_name' => $player->name,
        ]);

        return [
            'success' => true,
            'player_id' => $player->id,
        ];
    }

    /**
     * Procesar validación del drawer
     *
     * 1. Verificar que quien valida sea el drawer
     * 2. Obtener el jugador que está siendo validado
     * 3. Si es correcto:
     *    - Sumar puntos al guesser
     *    - Sumar puntos al drawer
     *    - Bloquear al jugador
     *    - Verificar si todos adivinaron
     * 4. Si es incorrecto:
     *    - No sumar puntos
     *    - Desbloquear al jugador (puede volver a intentar)
     * 5. Emitir evento AnswerValidatedEvent
     */
    private function processValidateClaim(GameMatch $match, Player $drawer, array $data): array
    {
        Log::info("[Pictionary] Processing validate_claim", [
            'match_id' => $match->id,
            'drawer_id' => $drawer->id,
            'validating_player_id' => $data['player_id'] ?? null,
            'is_correct' => $data['is_correct'] ?? null,
        ]);

        // 1. Verificar que quien valida sea el drawer
        $currentDrawerId = $this->getCurrentDrawerId($match);
        if ($drawer->id !== $currentDrawerId) {
            return [
                'success' => false,
                'message' => 'Solo el dibujante puede validar respuestas',
            ];
        }

        // 2. Obtener el jugador que está siendo validado
        $playerId = $data['player_id'] ?? null;
        $isCorrect = $data['is_correct'] ?? false;

        if (!$playerId) {
            return [
                'success' => false,
                'message' => 'No se especificó el jugador a validar',
            ];
        }

        $player = Player::find($playerId);
        if (!$player) {
            return [
                'success' => false,
                'message' => 'Jugador no encontrado',
            ];
        }

        // Usar PlayerManager - IMPORTANTE: pasar scoreCalculator para que awardPoints funcione
        $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);

        $forceEnd = false;
        $endReason = null;
        $points = 0;

        if ($isCorrect) {
            // ✅ RESPUESTA VALIDADA COMO CORRECTA
            Log::info("[Pictionary] Answer validated as correct", [
                'match_id' => $match->id,
                'player_id' => $playerId,
            ]);

            $currentWord = $match->game_state['current_word'] ?? [];

            $context = [
                'difficulty' => $currentWord['difficulty'] ?? 'medium',
            ];

            // Calcular tiempo si hay timer
            $timerData = $match->game_state['timer_system']['timers']['round'] ?? null;
            if ($timerData) {
                $startedAt = \Carbon\Carbon::parse($timerData['started_at']);
                $timeTaken = now()->diffInSeconds($startedAt);
                $context['time_taken'] = $timeTaken;
                $context['time_limit'] = $timerData['duration'] ?? 60;
            }

            // Sumar puntos al guesser (PlayerManager emite evento automáticamente)
            $guessPoints = $playerManager->awardPoints($playerId, 'correct_guess', $context, $match);

            // Sumar puntos al drawer
            $drawerPoints = $playerManager->awardPoints($drawer->id, 'drawer_success', $context, $match);

            $points = $guessPoints;

            // Registrar acción en game_state
            $gameState = $match->game_state;
            $gameState['actions'][$playerId] = [
                'type' => 'guess',
                'is_correct' => true,
                'guess' => $currentWord['word'] ?? 'unknown',
                'timestamp' => now()->toDateTimeString(),
                'points' => $guessPoints,
            ];
            $match->game_state = $gameState;
            $match->save();

            // Bloquear jugador
            $lockResult = $playerManager->lockPlayer($playerId, $match, $player, [
                'is_correct' => true,
                'points' => $guessPoints,
            ]);

            $this->savePlayerManager($match, $playerManager);

            // Terminar ronda inmediatamente cuando alguien adivina correctamente
            $forceEnd = true;
            $endReason = 'player_guessed_correct';

            Log::info("[Pictionary] Player guessed correctly - ending round", [
                'match_id' => $match->id,
                'player_id' => $playerId,
                'player_name' => $player->name,
            ]);
        } else {
            // ❌ RESPUESTA VALIDADA COMO INCORRECTA
            Log::info("[Pictionary] Answer validated as incorrect", [
                'match_id' => $match->id,
                'player_id' => $playerId,
            ]);

            // Bloquear al jugador (solo tiene 1 intento)
            $playerManager->lockPlayer($playerId, $match, $player, [
                'is_correct' => false,
                'reason' => 'incorrect_guess',
            ]);

            // Registrar acción en game_state
            $gameState = $match->game_state;
            $gameState['actions'][$playerId] = [
                'type' => 'guess',
                'is_correct' => false,
                'timestamp' => now()->toDateTimeString(),
            ];
            $match->game_state = $gameState;
            $match->save();

            $this->savePlayerManager($match, $playerManager);

            // Verificar si todos los guessers ya intentaron y ninguno acertó
            $guessers = $playerManager->getPlayersWithRoundRole('guesser');
            $lockedGuessers = array_filter($guessers, function ($playerId) use ($playerManager) {
                return $playerManager->isPlayerLocked($playerId);
            });
            
            if (count($lockedGuessers) >= count($guessers) && count($guessers) > 0) {
                $forceEnd = true;
                $endReason = 'all_guessers_failed';

                Log::info("[Pictionary] All guessers failed - ending round", [
                    'match_id' => $match->id,
                    'total_guessers' => count($guessers),
                    'locked_guessers' => count($lockedGuessers),
                ]);
            }
        }

        // 5. Emitir evento AnswerValidatedEvent
        event(new \App\Events\Pictionary\AnswerValidatedEvent(
            $match,
            $player,
            $isCorrect,
            $points
        ));

        return [
            'success' => true,
            'player_id' => $playerId,
            'data' => [
                'is_correct' => $isCorrect,
                'points' => $points,
            ],
            'force_end' => $forceEnd,
            'end_reason' => $endReason,
        ];
    }

    /**
     * Procesar stroke del dibujo.
     */
    private function processDrawStroke(GameMatch $match, Player $player, array $data): array
    {
        // Verificar que el jugador sea el drawer actual
        $currentDrawerId = $this->getCurrentDrawerId($match);
        if ($player->id !== $currentDrawerId) {
            return [
                'success' => false,
                'message' => 'Solo el dibujante puede dibujar',
            ];
        }

        // Obtener stroke del request
        $stroke = $data['stroke'] ?? null;
        if (!$stroke) {
            return [
                'success' => false,
                'message' => 'No se envió stroke',
            ];
        }

        $gameState = $match->game_state;

        // Solo guardar en canvas_data si NO es una continuación
        // Esto evita duplicar strokes cuando se envían en paquetes parciales
        $isContinuation = $stroke['is_continuation'] ?? false;

        if (!$isContinuation) {
            // Nuevo stroke - inicializar entrada en canvas_data
            $gameState['canvas_data'][] = [
                'points' => $stroke['points'],
                'color' => $stroke['color'],
                'size' => $stroke['size'],
            ];
            $match->game_state = $gameState;
            $match->save();
        } else {
            // Continuación - agregar puntos al último stroke
            $lastIndex = count($gameState['canvas_data']) - 1;
            if ($lastIndex >= 0) {
                $gameState['canvas_data'][$lastIndex]['points'] = array_merge(
                    $gameState['canvas_data'][$lastIndex]['points'],
                    $stroke['points']
                );
                $match->game_state = $gameState;
                $match->save();
            }
        }

        // Emitir evento DrawStrokeEvent para broadcast en tiempo real
        Log::info('[Pictionary] About to emit DrawStrokeEvent', [
            'player_id' => $player->id,
            'room_code' => $match->room->code,
            'stroke_points' => count($stroke['points'] ?? []),
        ]);

        event(new \App\Events\Pictionary\DrawStrokeEvent($match, $player, $stroke));

        Log::info('[Pictionary] DrawStrokeEvent emitted successfully');

        return [
            'success' => true,
            'stroke' => $stroke,
        ];
    }

    /**
     * Procesar limpieza del canvas.
     */
    private function processClearCanvas(GameMatch $match, Player $player): array
    {
        // Verificar que el jugador sea el drawer actual
        $currentDrawerId = $this->getCurrentDrawerId($match);
        if ($player->id !== $currentDrawerId) {
            return [
                'success' => false,
                'message' => 'Solo el dibujante puede limpiar el canvas',
            ];
        }

        // Limpiar canvas_data
        $gameState = $match->game_state;
        $gameState['canvas_data'] = [];
        $match->game_state = $gameState;
        $match->save();

        // Emitir evento CanvasClearedEvent
        event(new \App\Events\Pictionary\CanvasClearedEvent($match, $player));

        return [
            'success' => true,
            'message' => 'Canvas limpiado',
        ];
    }

    /**
     * Hook llamado ANTES de emitir DrawingStartedEvent.
     * Aquí preparamos los datos de la ronda para que estén disponibles cuando
     * PhaseManager emita DrawingStartedEvent.
     */
    protected function onRoundStarting(GameMatch $match): void
    {
        Log::info('[Pictionary] onRoundStarting hook called');
        
        // Llamar a startNewRound() que prepara los datos específicos
        $this->startNewRound($match);
    }

    /**
     * Iniciar nueva ronda - Preparar datos específicos de Pictionary.
     *
     * NOTA: PlayerManager::reset() ya se llama automáticamente en handleNewRound().
     * Aquí solo hacemos la lógica específica del juego.
     */
    protected function startNewRound(GameMatch $match): void
    {
        Log::info('[Pictionary] startNewRound hook');
        
        // NOTA: PlayerManager::reset() ya se llama automáticamente en handleNewRound()
        // Aquí solo hacemos la lógica específica del juego
        
        // Limpiar acciones del game_state
        $gameState = $match->game_state;
        $gameState['actions'] = [];
        $gameState['phase'] = 'playing';
        $match->game_state = $gameState;
        $match->save();

        // 1. Rotar drawer
        $this->rotateDrawer($match);

        // 2. Cargar siguiente palabra
        $word = $this->loadNextWord($match);

        // 3. Limpiar canvas
        $gameState = $match->game_state;
        $gameState['canvas_data'] = [];
        $match->game_state = $gameState;
        $match->save();

        // 4. Asignar roles (drawer/guesser)
        $this->assignRoles($match);

        // 5. Emitir WordRevealedEvent (solo al drawer)
        $this->emitWordRevealedEvent($match, $word);

        Log::info('[Pictionary] Round data prepared', [
            'match_id' => $match->id,
            'word' => $word['word'] ?? 'N/A',
            'drawer_id' => $this->getCurrentDrawerId($match)
        ]);
    }

    /**
     * Finalizar ronda actual.
     * 
     * SIGUE EL PROTOCOLO DE MOCKUP:
     * - Obtener scores desde PlayerManager (no ScoreManager)
     * - PlayerManager contiene los scores actualizados después de awardPoints()
     */
    public function endCurrentRound(GameMatch $match): void
    {
        Log::info("[Pictionary] Ending current round", ['match_id' => $match->id]);

        // Obtener resultados de la ronda
        $results = $this->getRoundResults($match);
        
        // Obtener scores desde PlayerManager (donde se actualizan con awardPoints)
        $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);
        $scores = $playerManager->getScores();

        Log::info("[Pictionary] Round end scores from PlayerManager", [
            'match_id' => $match->id,
            'scores' => $scores,
        ]);

        // Completar ronda (esto emite RoundEndedEvent con los scores)
        $this->completeRound($match, $results, $scores);
    }

    /**
     * Sobrescribir completeRound para usar scores de PlayerManager.
     * 
     * BaseGameEngine::completeRound() usa getScores() que busca en scoring_system,
     * pero Pictionary guarda scores en PlayerManager (player_system).
     * 
     * Si se pasan scores como tercer parámetro, los usamos directamente.
     */
    protected function completeRound(GameMatch $match, array $results = [], array $scores = []): void
    {
        Log::info("[Pictionary] Completing round", [
            'match_id' => $match->id,
            'has_scores' => !empty($scores),
        ]);

        // Si no se pasaron scores, obtenerlos desde PlayerManager
        if (empty($scores)) {
            $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);
            $scores = $playerManager->getScores();
        }

        // Llamar al método padre pero con nuestros scores
        $roundManager = $this->getRoundManager($match);
        $timerService = $this->isModuleEnabled($match, 'timer_system')
            ? $this->getTimerService($match)
            : null;

        // RoundManager maneja: emitir evento, crear timer countdown, etc.
        $roundManager->completeRound($match, $results, $scores, $timerService);

        // Guardar TimerService si se creó un timer
        if ($timerService !== null) {
            $this->saveTimerService($match, $timerService);
        }

        // Llamar al hook para que el juego ejecute lógica custom después del evento
        $currentRound = $roundManager->getCurrentRound();
        $this->onRoundEnded($match, $currentRound, $results, $scores);

        // Guardar estado actualizado
        $this->saveRoundManager($match, $roundManager);

        // Verificar si el juego terminó
        if ($roundManager->isGameComplete()) {
            Log::info("[Pictionary] Game complete, finalizing", [
                'match_id' => $match->id,
            ]);
            $this->finalize($match);
            return;
        }
    }

    /**
     * Obtener resultados de la ronda actual.
     */
    protected function getRoundResults(GameMatch $match): array
    {
        $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);
        $allActions = $match->game_state['actions'] ?? [];
        $currentWord = $this->getCurrentWord($match);
        $currentDrawerId = $this->getCurrentDrawerId($match);

        $guessers = [];
        foreach ($allActions as $playerId => $action) {
            if (isset($action['is_correct']) && $action['is_correct'] === true) {
                $guessers[] = [
                    'player_id' => $playerId,
                    'guess' => $action['guess'] ?? null,
                    'is_correct' => true,
                    'timestamp' => $action['timestamp'] ?? now()->toDateTimeString(),
                ];
            }
        }

        return [
            'actions' => $allActions,
            'word' => $currentWord,
            'drawer_id' => $currentDrawerId,
            'guessers' => $guessers,
            'total_correct' => count($guessers),
        ];
    }

    // ========================================================================
    // Callbacks de fase
    // ========================================================================

    /**
     * Callback cuando termina la fase de dibujo.
     */
    public function handleDrawingEnded(GameMatch $match, array $phaseData): void
    {
        Log::info('[Pictionary] handleDrawingEnded callback called', [
            'match_id' => $match->id,
            'phase' => $phaseData['name'] ?? 'drawing'
        ]);

        // Con fase única, al completar el ciclo finalizamos la ronda
        $this->endCurrentRound($match);
    }

    // ========================================================================
    // GESTIÓN DE PALABRAS
    // ========================================================================

    /**
     * Seleccionar palabras según dificultad y número de rondas.
     *
     * Asegura que:
     * - No haya duplicados
     * - Haya suficientes palabras para todas las rondas
     * - Si no hay suficientes en la dificultad seleccionada, usa otras dificultades
     *
     * @param array $wordsData Array con categorías ['easy' => [...], 'medium' => [...], 'hard' => [...]]
     * @param int $totalRounds Número de rondas solicitadas
     * @param string $difficulty Dificultad: 'easy', 'medium', 'hard', 'mixed'
     * @return array Array de palabras seleccionadas (sin duplicados)
     */
    private function selectWords(array $wordsData, int $totalRounds, string $difficulty): array
    {
        $selectedWords = [];

        // Paso 1: Recolectar palabras según dificultad
        if ($difficulty === 'mixed') {
            // Mezclar todas las dificultades
            foreach (['easy', 'medium', 'hard'] as $diff) {
                if (isset($wordsData[$diff]) && is_array($wordsData[$diff])) {
                    $selectedWords = array_merge($selectedWords, $wordsData[$diff]);
                }
            }
        } else {
            // Solo la dificultad especificada
            if (isset($wordsData[$difficulty]) && is_array($wordsData[$difficulty])) {
                $selectedWords = $wordsData[$difficulty];
            }
        }

        // Paso 2: Verificar si hay suficientes palabras
        $availableCount = count($selectedWords);

        if ($availableCount < $totalRounds) {
            Log::warning("[Pictionary] Insufficient words for difficulty '{$difficulty}'", [
                'requested' => $totalRounds,
                'available' => $availableCount,
                'difficulty' => $difficulty
            ]);

            // Si no hay suficientes, agregar palabras de otras dificultades
            if ($difficulty !== 'mixed') {
                $otherDifficulties = array_diff(['easy', 'medium', 'hard'], [$difficulty]);

                foreach ($otherDifficulties as $otherDiff) {
                    if (isset($wordsData[$otherDiff]) && is_array($wordsData[$otherDiff])) {
                        $selectedWords = array_merge($selectedWords, $wordsData[$otherDiff]);
                    }

                    // Verificar si ya tenemos suficientes
                    if (count($selectedWords) >= $totalRounds) {
                        break;
                    }
                }

                Log::info("[Pictionary] Added words from other difficulties", [
                    'total_now' => count($selectedWords)
                ]);
            }
        }

        // Paso 3: Eliminar duplicados (por si acaso)
        // Usar array_unique basado en el campo 'word'
        $uniqueWords = [];
        $seenWords = [];

        foreach ($selectedWords as $wordData) {
            $word = $wordData['word'] ?? null;
            if ($word && !in_array($word, $seenWords)) {
                $uniqueWords[] = $wordData;
                $seenWords[] = $word;
            }
        }

        // Paso 4: Aleatorizar
        shuffle($uniqueWords);

        // Paso 5: Limitar al número de rondas
        $finalWords = array_slice($uniqueWords, 0, $totalRounds);

        // Verificación final
        if (count($finalWords) < $totalRounds) {
            Log::error("[Pictionary] Not enough unique words available", [
                'requested' => $totalRounds,
                'got' => count($finalWords)
            ]);
        }

        Log::info("[Pictionary] Words selected successfully", [
            'total' => count($finalWords),
            'requested' => $totalRounds,
            'difficulty' => $difficulty
        ]);

        return $finalWords;
    }

    /**
     * Cargar la siguiente palabra basándose en la ronda actual.
     */
    private function loadNextWord(GameMatch $match): array
    {
        $roundManager = $this->getRoundManager($match);
        $currentRound = $roundManager->getCurrentRound();

        $word = $this->getWordByRound($match, $currentRound);

        $this->setCurrentWord($match, $word);

        return $word;
    }

    /**
     * Obtener palabra por número de ronda.
     */
    private function getWordByRound(GameMatch $match, int $roundNumber): array
    {
        $words = $match->game_state['words'] ?? [];
        $wordIndex = $roundNumber - 1; // Convertir a índice 0-based

        if (!isset($words[$wordIndex])) {
            throw new \RuntimeException("No hay palabra para la ronda {$roundNumber}");
        }

        return $words[$wordIndex];
    }

    /**
     * Establecer la palabra actual en el estado del juego.
     */
    private function setCurrentWord(GameMatch $match, array $word): void
    {
        $gameState = $match->game_state;
        $gameState['current_word'] = $word;
        $match->game_state = $gameState;
        $match->save();
    }

    /**
     * Obtener la palabra actual.
     */
    private function getCurrentWord(GameMatch $match): ?array
    {
        return $match->game_state['current_word'] ?? null;
    }

    /**
     * Filtrar game_state para remover información sensible antes de broadcast.
     *
     * En Pictionary, la palabra actual (`current_word`) NO debe enviarse a todos los jugadores
     * en eventos como RoundStartedEvent. Solo el drawer recibe la palabra vía WordRevealedEvent
     * en su canal privado.
     */
    protected function filterGameStateForBroadcast(array $gameState, \App\Models\GameMatch $match): array
    {
        $filtered = $gameState;

        // Remover la palabra actual para que no llegue a los guessers
        unset($filtered['current_word']);

        return $filtered;
    }

    // ========================================================================
    // GESTIÓN DE DRAWERS
    // ========================================================================

    /**
     * Rotar al siguiente drawer.
     */
    private function rotateDrawer(GameMatch $match): void
    {
        $gameState = $match->game_state;
        $rotation = $gameState['drawer_rotation'] ?? [];
        $currentIndex = $gameState['current_drawer_index'] ?? 0;

        // Avanzar al siguiente drawer
        $nextIndex = ($currentIndex + 1) % count($rotation);

        $gameState['current_drawer_index'] = $nextIndex;
        $match->game_state = $gameState;
        $match->save();

        Log::info("[Pictionary] Drawer rotated", [
            'match_id' => $match->id,
            'previous_index' => $currentIndex,
            'new_index' => $nextIndex,
            'new_drawer_id' => $rotation[$nextIndex]
        ]);
    }

    /**
     * Obtener el ID del drawer actual.
     */
    private function getCurrentDrawerId(GameMatch $match): ?int
    {
        $rotation = $match->game_state['drawer_rotation'] ?? [];
        $currentIndex = $match->game_state['current_drawer_index'] ?? 0;

        return $rotation[$currentIndex] ?? null;
    }

    /**
     * Asignar roles (drawer/guesser) a los jugadores usando PlayerManager.
     *
     * Usa roles de ronda (roundRoles) porque drawer rota cada turno.
     * Asigna manualmente basándose en current_drawer_index:
     * - 1 jugador como drawer (rotando secuencialmente)
     * - El resto como guessers
     */
    private function assignRoles(GameMatch $match): void
    {
        $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);
        $roundManager = $this->getRoundManager($match);
        $currentRound = $roundManager->getCurrentRound();

        // Obtener orden de rotación de drawers
        $drawerRotation = $match->game_state['drawer_rotation'] ?? [];
        $currentDrawerIndex = $match->game_state['current_drawer_index'] ?? 0;
        $currentDrawerId = $drawerRotation[$currentDrawerIndex] ?? null;

        Log::info("[Pictionary] Assigning roles", [
            'current_round' => $currentRound,
            'current_drawer_index' => $currentDrawerIndex,
            'current_drawer_id' => $currentDrawerId,
            'drawer_rotation' => $drawerRotation,
        ]);

        // IMPORTANTE: No usar rotateComplementaryRoles que calcula basándose en currentRound
        // Mejor asignar roles manualmente basándose en current_drawer_index que ya fue rotado

        // Limpiar roles anteriores
        foreach ($drawerRotation as $playerId) {
            $playerManager->removeRoundRole($playerId);
        }

        // Asignar drawer actual
        $playerManager->assignRoundRole($currentDrawerId, 'drawer');

        // Asignar guessers (todos los demás)
        foreach ($drawerRotation as $playerId) {
            if ($playerId !== $currentDrawerId) {
                $playerManager->assignRoundRole($playerId, 'guesser');
            }
        }

        // Guardar PlayerManager actualizado
        $this->savePlayerManager($match, $playerManager);

        Log::info("[Pictionary] Roles assigned", [
            'round' => $currentRound,
            'drawer_id' => $currentDrawerId,
            'guesser_ids' => array_filter($drawerRotation, fn($id) => $id !== $currentDrawerId),
        ]);
    }

    /**
     * Emitir WordRevealedEvent al drawer actual.
     *
     * Este evento se envía de forma privada solo al drawer para revelarle
     * la palabra que debe dibujar sin que los demás jugadores la vean.
     *
     * @param GameMatch $match La partida actual
     * @param array $word Los datos de la palabra (word, difficulty, etc.)
     */
    private function emitWordRevealedEvent(GameMatch $match, array $word): void
    {
        // 1. Obtener ID del drawer actual
        $drawerId = $this->getCurrentDrawerId($match);

        if (!$drawerId) {
            Log::warning("[Pictionary] Cannot emit WordRevealedEvent: no drawer found", [
                'match_id' => $match->id
            ]);
            return;
        }

        // 2. Obtener modelo Player del drawer
        $drawer = Player::find($drawerId);

        if (!$drawer) {
            Log::error("[Pictionary] Cannot emit WordRevealedEvent: drawer not found in DB", [
                'match_id' => $match->id,
                'drawer_id' => $drawerId
            ]);
            return;
        }

        // 3. Obtener ronda actual
        $roundManager = $this->getRoundManager($match);
        $currentRound = $roundManager->getCurrentRound();

        // 4. Emitir evento privado al drawer
        event(new WordRevealedEvent(
            $match,
            $drawer,
            $word['word'],
            $word['difficulty'] ?? 'medium',
            $currentRound
        ));

        Log::info("[Pictionary] WordRevealedEvent emitted to drawer", [
            'match_id' => $match->id,
            'drawer_id' => $drawerId,
            'drawer_user_id' => $drawer->user_id,
            'word' => $word['word'],
            'round' => $currentRound
        ]);
    }

    // ========================================================================
    // UTILIDADES
    // ========================================================================

    /**
     * Normalizar string para comparación (lowercase, sin acentos, trim).
     */
    private function normalizeString(string $str): string
    {
        // Convertir a minúsculas
        $str = mb_strtolower($str, 'UTF-8');

        // Eliminar acentos
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);

        // Trim
        $str = trim($str);

        return $str;
    }

    // ========================================================================
    // MÉTODOS OBLIGATORIOS DE BaseGameEngine
    // ========================================================================

    /**
     * Verificar condición de victoria.
     */
    public function checkWinCondition(GameMatch $match): ?Player
    {
        // En Pictionary, el ganador se determina al final por puntos
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
     * Hook OPCIONAL: Lógica específica antes de pausar por desconexión.
     *
     * BaseGameEngine ya ejecutará automáticamente:
     * - Pausar timer de ronda
     * - Marcar game_state.paused = true
     * - Emitir PlayerDisconnectedEvent
     *
     * Aquí solo ejecutamos lógica específica de Pictionary:
     * Si el drawer se desconecta durante el juego, terminar la ronda
     * inmediatamente antes de pausar (porque sin drawer no se puede continuar).
     */
    protected function beforePlayerDisconnectedPause(GameMatch $match, Player $player): void
    {
        // Si el drawer se desconecta durante el juego, terminar ronda inmediatamente
        $currentDrawerId = $this->getCurrentDrawerId($match);
        $phase = $match->game_state['phase'] ?? 'waiting';

        if ($player->id === $currentDrawerId && $phase === 'playing') {
            Log::warning("[Pictionary] Drawer disconnected - ending round before pause", [
                'match_id' => $match->id,
                'drawer_id' => $player->id
            ]);

            // Terminar ronda actual usando método heredado de BaseGameEngine
            $roundManager = $this->getRoundManager($match);
            if (!$roundManager->isGameComplete()) {
                // Terminar ronda actual
                $this->endCurrentRound($match);
            }
        }
    }

    /**
     * Override: Manejar reconexión de jugador.
     *
     * SIGUE EL PROTOCOLO DE MOCKUP:
     * - No sobrescribir, usar el comportamiento por defecto de BaseGameEngine
     * - BaseGameEngine::onPlayerReconnected() automáticamente:
     *   1. Quita la pausa
     *   2. Llama a handleNewRound(advanceRound: false) para reiniciar la ronda actual
     *   3. Esto emite RoundStartedEvent y DrawingStartedEvent automáticamente
     *   4. Emite PlayerReconnectedEvent
     *
     * El timer se restaura automáticamente cuando llega el DrawingStartedEvent.
     */
    // No es necesario sobrescribir - usar comportamiento por defecto igual que Mockup

}
