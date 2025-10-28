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

        // TODO: Seleccionar palabras según dificultad y número de rondas
        // Por ahora, seleccionar aleatoriamente
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

        // Cachear players en _config (1 query, 1 sola vez)
        $this->cachePlayersInState($match);

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
     * Hook cuando el juego empieza - FASE 3 (POST-COUNTDOWN)
     *
     * Establece la rotación de drawers y prepara la primera ronda.
     */
    protected function onGameStart(GameMatch $match): void
    {
        Log::info("[Pictionary] Game starting - FASE 3", ['match_id' => $match->id]);

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

        // Usar handleNewRound() para iniciar la primera ronda
        $this->handleNewRound($match, advanceRound: false);

        Log::info("[Pictionary] First round started via handleNewRound()", [
            'match_id' => $match->id,
            'room_code' => $match->room->code,
            'drawer_rotation' => $playerIds
        ]);
    }

    /**
     * ✅ SISTEMA UNIFICADO DE FASES: NO emitir timing en RoundStartedEvent
     *
     * El timing ahora se emite via PhaseChangedEvent en onRoundStarted()
     */
    protected function getRoundStartTiming(GameMatch $match): ?array
    {
        return null; // NO timing en RoundStartedEvent
    }

    /**
     * ✅ SISTEMA UNIFICADO DE FASES: Emitir PhaseChangedEvent con timing
     *
     * Este hook se ejecuta DESPUÉS de RoundStartedEvent.
     * Aquí obtenemos el PhaseManager y emitimos el evento con timing metadata.
     */
    protected function onRoundStarted(GameMatch $match, int $currentRound, int $totalRounds): void
    {
        $roundManager = $this->getRoundManager($match);
        $phaseManager = $roundManager->getTurnManager(); // Es PhaseManager

        if (!$phaseManager) {
            Log::error("[Pictionary] PhaseManager NOT FOUND in RoundManager", ['match_id' => $match->id]);
            return;
        }

        $currentPhase = $phaseManager->getCurrentPhaseName();
        $timingInfo = $phaseManager->getTimingInfo();

        $timing = [
            'server_time' => now()->timestamp,
            'duration' => $timingInfo['delay'] ?? 0
        ];

        Log::info("[Pictionary] Emitting PhaseChangedEvent after RoundStarted", [
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

    /**
     * Procesar acción de ronda - Puede ser un intento de adivinar o un stroke del dibujo.
     *
     * Flujo para GUESS:
     * 1. Verificar que el jugador no sea el drawer
     * 2. Verificar que no esté bloqueado (ya adivinó)
     * 3. Verificar la respuesta contra la palabra actual
     * 4. Si es correcta:
     *    - Sumar puntos al guesser (base + speed bonus)
     *    - Sumar puntos al drawer
     *    - Bloquear al jugador
     *    - Si todos adivinaron, terminar ronda
     * 5. Si es incorrecta:
     *    - No hacer nada (seguir intentando)
     *
     * Flujo para DRAW_STROKE:
     * 1. Verificar que el jugador sea el drawer actual
     * 2. Agregar stroke al canvas_data
     * 3. Broadcast del stroke a todos los jugadores
     *
     * @param GameMatch $match
     * @param Player $player
     * @param array $data ['action' => 'guess'|'draw_stroke', 'guess' => string, 'stroke' => array]
     * @return array
     */
    protected function processRoundAction(GameMatch $match, Player $player, array $data): array
    {
        $action = $data['action'] ?? null;

        if ($action === 'claim_answer') {
            return $this->processClaimAnswer($match, $player, $data);
        } elseif ($action === 'validate_claim') {
            return $this->processValidateClaim($match, $player, $data);
        } elseif ($action === 'draw_stroke') {
            return $this->processDrawStroke($match, $player, $data);
        } elseif ($action === 'clear_canvas') {
            return $this->processClearCanvas($match, $player);
        }

        return [
            'success' => false,
            'message' => 'Acción desconocida',
        ];
    }

    /**
     * Procesar intento de adivinar.
     *
     * TODO: Implementar lógica de validación de respuestas
     * - Normalizar la respuesta (lowercase, sin acentos, trim)
     * - Comparar con la palabra actual
     * - Calcular puntos con speed bonus
     */
    private function processGuess(GameMatch $match, Player $player, array $data): array
    {
        Log::info("[Pictionary] Processing guess", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'guess' => $data['guess'] ?? null
        ]);

        // 1. Validar que se envió una respuesta
        if (!isset($data['guess'])) {
            return [
                'success' => false,
                'message' => 'No se envió una respuesta',
            ];
        }

        // 2. Verificar que el jugador no sea el drawer
        $currentDrawerId = $this->getCurrentDrawerId($match);
        if ($player->id === $currentDrawerId) {
            return [
                'success' => false,
                'message' => 'El dibujante no puede adivinar',
            ];
        }

        // 3. Verificar que el jugador no esté bloqueado
        $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);
        if ($playerManager->isPlayerLocked($player->id)) {
            return [
                'success' => false,
                'message' => 'Ya adivinaste esta palabra',
            ];
        }

        // 4. Obtener la palabra actual
        $currentWord = $this->getCurrentWord($match);
        if (!$currentWord) {
            return [
                'success' => false,
                'message' => 'No hay palabra actual',
            ];
        }

        // 5. Normalizar y verificar la respuesta
        $guess = $this->normalizeString($data['guess']);
        $correctWord = $this->normalizeString($currentWord['word']);

        $isCorrect = ($guess === $correctWord);

        // 6. Registrar la acción del jugador
        $playerManager->setPlayerAction($player->id, [
            'type' => 'guess',
            'guess' => $data['guess'],
            'is_correct' => $isCorrect,
            'timestamp' => now()->toDateTimeString(),
        ]);

        $resultData = [];
        $forceEnd = false;

        if ($isCorrect) {
            // ✅ RESPUESTA CORRECTA
            Log::info("[Pictionary] Correct guess!", [
                'match_id' => $match->id,
                'player_id' => $player->id,
                'word' => $currentWord['word']
            ]);

            // Calcular puntos con speed bonus
            $context = [
                'difficulty' => $currentWord['difficulty'] ?? 'medium',
            ];

            // Calcular elapsed time para speed bonus
            $timerDuration = $gameConfig['modules']['timer_system']['round_duration'] ?? null;
            if ($timerDuration) {
                try {
                    $elapsedTime = $this->getElapsedTime($match, 'round');
                    $context['time_taken'] = $elapsedTime;
                    $context['time_limit'] = $timerDuration;
                } catch (\Exception $e) {
                    Log::debug("[Pictionary] No timer available for speed bonus");
                }
            }

            // Sumar puntos al guesser
            $guessPoints = $calculator->calculate('correct_guess', $context);
            $this->awardPoints($match, $player->id, 'correct_guess', $context, $calculator);

            // Sumar puntos al drawer
            $drawer = Player::find($currentDrawerId);
            if ($drawer) {
                $drawerPoints = $calculator->calculate('drawer_success', $context);
                $this->awardPoints($match, $drawer->id, 'drawer_success', $context, $calculator);
            }

            $resultData = [
                'is_correct' => true,
                'points' => $guessPoints,
                'guess' => $data['guess'],
            ];

            // Bloquear jugador
            $lockResult = $playerManager->lockPlayer($player->id, $match, $player, $resultData);
            $this->savePlayerManager($match, $playerManager);

            // Obtener score total del jugador
            $scoreManager = $this->getScoreManager($match);
            $totalScore = $scoreManager->getScore($player->id);

            // Emitir evento CorrectGuessEvent
            event(new \App\Events\Pictionary\CorrectGuessEvent(
                $match,
                $player,
                $data['guess'],
                $guessPoints,
                $totalScore
            ));

            // Verificar si todos los guessers adivinaron
            $allGuessers = $playerManager->getPlayersWithRoundRole('guesser');
            $lockedGuessers = array_filter($allGuessers, function ($playerId) use ($playerManager) {
                return $playerManager->isPlayerLocked($playerId);
            });

            if (count($lockedGuessers) >= count($allGuessers)) {
                // Todos los guessers adivinaron → termina la ronda
                $forceEnd = true;
                $endReason = 'all_players_guessed';

                Log::info("[Pictionary] All guessers got it right - ending round", [
                    'match_id' => $match->id,
                    'total_guessers' => count($allGuessers),
                    'locked_guessers' => count($lockedGuessers)
                ]);
            }

        } else {
            // ❌ RESPUESTA INCORRECTA
            Log::info("[Pictionary] Incorrect guess", [
                'match_id' => $match->id,
                'player_id' => $player->id,
                'guess' => $data['guess']
            ]);

            $resultData = [
                'is_correct' => false,
                'guess' => $data['guess'],
            ];

            // En Pictionary, no se bloquea por respuesta incorrecta
            // Los jugadores pueden seguir intentando
        }

        return array_merge([
            'success' => true,
            'should_end_turn' => $forceEnd ?? false,
            'end_reason' => $endReason ?? null,
            'player_id' => $player->id,
        ], $resultData);
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

        // Usar PlayerManager (unifica scores + state)
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

            // Registrar acción de guess correcto (para getAllPlayerResults)
            $playerManager->setPlayerAction($playerId, [
                'type' => 'guess',
                'is_correct' => true,
                'guess' => $currentWord['word'] ?? 'unknown',
                'timestamp' => now()->toDateTimeString(),
            ]);

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

            // Registrar acción de guess incorrecto
            $playerManager->setPlayerAction($playerId, [
                'type' => 'guess',
                'is_correct' => false,
                'timestamp' => now()->toDateTimeString(),
            ]);

            $this->savePlayerManager($match, $playerManager);

            // Verificar si todos los guessers ya intentaron y ninguno acertó
            if ($playerManager->haveAllRolePlayersFailedAttempt('guesser')) {
                $forceEnd = true;
                $endReason = 'all_guessers_failed';

                $guessers = $playerManager->getPlayersWithRoundRole('guesser');
                Log::info("[Pictionary] All guessers failed - ending round", [
                    'match_id' => $match->id,
                    'total_guessers' => count($guessers),
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
            'should_end_turn' => $forceEnd,
            'end_reason' => $endReason,
            'player_id' => $playerId,
            'is_correct' => $isCorrect,
            'points' => $points,
        ];
    }

    /**
     * Procesar stroke del dibujo.
     *
     * TODO: Implementar broadcast en tiempo real del stroke
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
     * Hook OPCIONAL: Preparar datos específicos para la nueva ronda.
     *
     * BaseGameEngine ya ejecutó:
     * - PlayerManager::reset() (desbloquea jugadores, emite PlayersUnlockedEvent)
     * - RoundManager::advanceToNextRound() (incrementa contador)
     *
     * Aquí SOLO ejecutamos lógica específica de Pictionary:
     * - Rotar drawer
     * - Cargar siguiente palabra
     * - Limpiar canvas
     * - Asignar roles (drawer/guesser)
     * - Emitir WordRevealedEvent (solo al drawer)
     */
    protected function onRoundStarting(GameMatch $match): void
    {
        Log::info("[Pictionary] Preparing round data", ['match_id' => $match->id]);

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

        Log::info("[Pictionary] Round data prepared", [
            'match_id' => $match->id,
            'word' => $word['word'],
            'drawer_id' => $this->getCurrentDrawerId($match)
        ]);
    }

    /**
     * Obtener resultados de la ronda actual (Método abstracto de BaseGameEngine).
     *
     * Formatea los datos específicos de Pictionary:
     * - Palabra actual y dificultad
     * - ID del drawer
     * - Lista de guessers que acertaron (con timestamps)
     * - Total de aciertos
     *
     * BaseGameEngine usa este método para:
     * - Emitir RoundEndedEvent con los resultados
     * - Guardar historial de rondas
     */
    protected function getRoundResults(GameMatch $match): array
    {
        $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);
        $allActions = $playerManager->getAllActions();
        $currentWord = $this->getCurrentWord($match);
        $currentDrawerId = $this->getCurrentDrawerId($match);

        $playerResults = [];
        $guessers = [];

        foreach ($allActions as $playerId => $action) {
            if ($action['type'] === 'guess' && $action['is_correct']) {
                $guessers[] = [
                    'player_id' => $playerId,
                    'guess' => $action['guess'],
                    'is_correct' => true,
                    'timestamp' => $action['timestamp'],
                ];
            }
        }

        return [
            'word' => $currentWord,
            'drawer_id' => $currentDrawerId,
            'guessers' => $guessers,
            'total_correct' => count($guessers),
        ];
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
            if ($roundManager->hasRoundsRemaining()) {
                // Llamar al método heredado de BaseGameEngine que maneja el flujo completo
                parent::endCurrentRound($match);
            }
        }
    }

    /**
     * Override: Manejar reconexión de jugador.
     *
     * A diferencia del comportamiento por defecto (que reinicia la ronda),
     * Pictionary solo resume el juego en su estado actual SIN rotarDrawer ni reiniciar.
     */
    public function onPlayerReconnected(GameMatch $match, Player $player): void
    {
        Log::info("[Pictionary] Player reconnected - resuming without restarting", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'player_name' => $player->name,
        ]);

        // 1. Quitar pausa
        $gameState = $match->game_state;
        $gameState['paused'] = false;
        unset($gameState['paused_reason']);
        unset($gameState['disconnected_player_id']);
        unset($gameState['paused_at']);
        $match->game_state = $gameState;
        $match->save();

        // 2. Reanudar timer si está configurado y estaba pausado
        if (isset($gameState['timer_system'])) {
            try {
                $timerService = $this->getTimerService($match);
                if ($timerService->hasTimer('round')) {
                    $timer = $timerService->getTimer('round');
                    if ($timer->isPaused()) {
                        $timerService->resumeTimer('round');
                        $this->saveTimerService($match, $timerService);
                        Log::info("[Pictionary] Round timer resumed", [
                            'match_id' => $match->id,
                            'timer_name' => 'round'
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // Timer no está disponible, continuar sin error
                Log::debug("[Pictionary] Timer not available, skipping timer resume", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // 3. Emitir evento de reconexión (sin reiniciar ronda)
        event(new \App\Events\Game\PlayerReconnectedEvent($match, $player, false));

        Log::info("[Pictionary] Game resumed at current state", [
            'match_id' => $match->id,
            'current_round' => $gameState['round_system']['current_round'] ?? null,
            'current_drawer_index' => $gameState['current_drawer_index'] ?? null,
        ]);
    }

    // ========================================================================
    // MÉTODOS HEREDADOS (NO REIMPLEMENTAR)
    // ========================================================================
    //
    // Los siguientes métodos se heredan de BaseGameEngine y NO deben sobrescribirse:
    // - getGameConfig(): Carga config.json del juego automáticamente
    // - getFinalScores(): Obtiene scores finales de PlayerManager automáticamente
    // - endCurrentRound(): Maneja el flujo completo de fin de ronda (llama getRoundResults())
    // - handlePlayerDisconnect/Reconnect(): OBSOLETOS, usar hooks beforePlayerDisconnectedPause() y afterPlayerReconnected()
    //
    // getGameConfig() y getFinalScores() ahora se heredan de BaseGameEngine
    // (implementación común para todos los juegos)
}
