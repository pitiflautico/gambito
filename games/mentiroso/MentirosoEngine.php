<?php

namespace Games\Mentiroso;

use App\Contracts\BaseGameEngine;
use App\Models\GameMatch;
use App\Models\Player;
use App\Services\Modules\TurnSystem\PhaseManager;
use App\Events\Game\PhaseChangedEvent;
use Illuminate\Support\Facades\Log;

/**
 * Mentiroso Game Engine
 *
 * Juego social de engaño y persuasión donde un orador defiende una frase
 * (verdadera o falsa) y los demás deben adivinar si dice la verdad.
 */
class MentirosoEngine extends BaseGameEngine
{
    /**
     * Score calculator instance (reutilizado para evitar instanciación repetida)
     */
    protected MentirosoScoreCalculator $scoreCalculator;

    public function __construct()
    {
        // Cargar configuración del juego (heredado de BaseGameEngine)
        $gameConfig = $this->getGameConfig();
        $scoringConfig = $gameConfig['scoring'] ?? [];

        // Inicializar calculator con la configuración
        $this->scoreCalculator = new MentirosoScoreCalculator($scoringConfig);
    }

    /**
     * Inicializar el juego - FASE 1 (LOBBY)
     *
     * Carga el banco de frases y configura la rotación de oradores.
     */
    public function initialize(GameMatch $match): void
    {
        Log::info("[Mentiroso] Initializing - FASE 1", ['match_id' => $match->id]);

        // Cargar frases desde statements.json
        $statementsPath = base_path('games/mentiroso/statements.json');
        $statementsData = json_decode(file_get_contents($statementsPath), true);

        // Cargar config.json para obtener configuraciones
        $gameConfig = $this->getGameConfig();
        $defaultRounds = $gameConfig['customizableSettings']['rounds']['default'] ?? 10;

        // Obtener configuración personalizada del usuario
        $gameSettings = $match->room->game_settings ?? [];
        $totalRounds = $gameSettings['rounds'] ?? $defaultRounds;

        // Seleccionar frases aleatorias
        $selectedStatements = $this->selectStatements($statementsData['statements'], $totalRounds);

        Log::info("[Mentiroso] Statements configured", [
            'match_id' => $match->id,
            'total_rounds' => $totalRounds,
            'statements_selected' => count($selectedStatements)
        ]);

        // Crear mapeo user_id => player_id para el frontend
        $userToPlayerMap = [];
        foreach ($match->players as $player) {
            $userToPlayerMap[$player->user_id] = $player->id;
        }

        // Guardar configuración inicial
        $match->game_state = [
            '_config' => [
                'game' => 'mentiroso',
                'initialized_at' => now()->toDateTimeString(),
                'user_to_player_map' => $userToPlayerMap,
                'timing' => $gameConfig['timing'] ?? null,
                'modules' => $gameConfig['modules'] ?? [],
            ],
            'phase' => 'waiting',
            'statements' => $selectedStatements,
            'current_statement' => null,
            'orador_rotation' => [], // Se inicializará en onGameStart
            'current_phase' => null, // preparation, persuasion, voting, results
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
                'total_rounds' => count($selectedStatements)
            ]
        ]);

        // Inicializar PlayerManager con roles
        $playerIds = $match->players->pluck('id')->toArray();
        $availableRoles = ['orador', 'votante'];

        $playerManager = new \App\Services\Modules\PlayerSystem\PlayerManager(
            $playerIds,
            $this->scoreCalculator,
            [
                'available_roles' => $availableRoles,
                'allow_multiple_persistent_roles' => false,
                'track_score_history' => false,
            ]
        );
        $this->savePlayerManager($match, $playerManager);

        Log::info("[Mentiroso] Initialized successfully", [
            'match_id' => $match->id,
            'total_players' => count($playerIds),
            'total_rounds' => $totalRounds
        ]);
    }

    /**
     * Hook cuando el juego empieza - FASE 3 (POST-COUNTDOWN)
     *
     * Establece la rotación de oradores y prepara la primera ronda.
     */
    protected function onGameStart(GameMatch $match): void
    {
        Log::info("[Mentiroso] Game starting - FASE 3", ['match_id' => $match->id]);

        // Establecer rotación de oradores (todos los jugadores)
        $playerIds = $match->players->pluck('id')->toArray();
        shuffle($playerIds); // Orden aleatorio

        $match->game_state = array_merge($match->game_state, [
            'phase' => 'playing',
            'started_at' => now()->toDateTimeString(),
            'orador_rotation' => $playerIds,
            'current_orador_index' => 0,
        ]);

        $match->save();

        // Usar handleNewRound() para iniciar la primera ronda
        $this->handleNewRound($match, advanceRound: false);

        Log::info("[Mentiroso] First round started via handleNewRound()", [
            'match_id' => $match->id,
            'room_code' => $match->room->code,
            'orador_rotation' => $playerIds
        ]);
    }

    /**
     * Override: No timing en RoundStartedEvent porque usamos PhaseManager
     *
     * Mentiroso usa PhaseManager con múltiples fases por ronda (preparation, persuasion, voting)
     * Cada fase tiene su propio timer emitido via PhaseChangedEvent.
     * RoundStartedEvent no debe incluir timing para evitar conflictos.
     */
    protected function getRoundStartTiming(GameMatch $match): ?array
    {
        return null;  // No timing en RoundStartedEvent - usamos PhaseChangedEvent
    }

    /**
     * Hook OPCIONAL: Preparar datos específicos para la nueva ronda.
     *
     * BaseGameEngine ya ejecutó:
     * - PlayerManager::reset() (desbloquea jugadores, emite PlayersUnlockedEvent)
     * - RoundManager::advanceToNextRound() (incrementa contador)
     *
     * Aquí SOLO ejecutamos lógica específica de Mentiroso:
     * - Rotar orador
     * - Cargar siguiente frase
     * - Asignar roles (orador/votantes)
     * - Iniciar PhaseManager (sistema de fases múltiples)
     * - Emitir StatementRevealedEvent (solo al orador)
     * - Iniciar timer de primera fase
     */
    protected function onRoundStarting(GameMatch $match): void
    {
        Log::info("[Mentiroso] Preparing round data", ['match_id' => $match->id]);

        // 1. Rotar orador
        $this->rotateOrador($match);

        // 2. Cargar siguiente frase
        $statement = $this->loadNextStatement($match);

        // 3. Asignar roles (orador/votantes)
        $this->assignRoles($match);

        // 4. Iniciar PhaseManager y fase de preparación
        $phaseManager = $this->createPhaseManager($match);
        $this->savePhaseManager($match, $phaseManager);

        // Establecer fase inicial y limpiar flags de completado
        $gameState = $match->game_state;
        $gameState['current_phase'] = 'preparation';
        unset($gameState['_completing_round']);  // Limpiar flag de concurrencia
        $match->game_state = $gameState;
        $match->save();

        Log::info("[Mentiroso] New round started", [
            'match_id' => $match->id,
            'statement' => $statement['text'],
            'orador_id' => $this->getCurrentOradorId($match),
            'phase' => 'preparation'
        ]);

        // Emitir evento personalizado con la frase (SOLO al orador, canal privado)
        $currentOradorId = $this->getCurrentOradorId($match);
        $orador = Player::find($currentOradorId);
        if ($orador) {
            $roundManager = $this->getRoundManager($match);
            event(new \App\Events\Mentiroso\StatementRevealedEvent(
                $match,
                $orador,
                $statement['text'],
                $statement['is_true'],
                $roundManager->getCurrentRound()
            ));
        }

        // Iniciar timer de la primera fase (preparation)
        $phaseManager->startTurnTimer();
        $this->savePhaseManager($match, $phaseManager);

        // Emitir evento de cambio de fase para notificar al frontend
        $timingInfo = $phaseManager->getTimingInfo();
        $timing = [
            'server_time' => now()->timestamp,
            'duration' => $timingInfo['delay'] ?? 0
        ];

        $event = new PhaseChangedEvent(
            $match,
            'preparation',
            '',  // No previous phase (nueva ronda)
            [
                'phase' => 'preparation',
                'game_state' => $this->filterGameStateForBroadcast($match->game_state, $match),
                'timing' => $timing
            ]
        );

        Log::info("[Mentiroso] Emitting PhaseChangedEvent for new round", [
            'match_id' => $match->id,
            'room_code' => $match->room->code,
            'phase' => 'preparation',
            'timing' => $timing
        ]);

        event($event);
    }

    /**
     * Procesar acción de ronda - Puede ser un voto
     */
    protected function processRoundAction(GameMatch $match, Player $player, array $data): array
    {
        $action = $data['action'] ?? null;

        if ($action === 'vote') {
            return $this->processVote($match, $player, $data);
        }

        return [
            'success' => false,
            'message' => 'Acción desconocida',
        ];
    }

    /**
     * Procesar voto de un jugador
     */
    private function processVote(GameMatch $match, Player $player, array $data): array
    {
        Log::info("[Mentiroso] Processing vote", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'vote' => $data['vote'] ?? null
        ]);

        // Verificar que el jugador sea votante (no el orador)
        $currentOradorId = $this->getCurrentOradorId($match);
        if ($player->id === $currentOradorId) {
            return [
                'success' => false,
                'message' => 'El orador no puede votar',
            ];
        }

        $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);

        // Verificar que no haya votado ya
        if ($playerManager->isPlayerLocked($player->id)) {
            return [
                'success' => false,
                'message' => 'Ya has votado',
            ];
        }

        // Obtener voto (true = dice verdad, false = está mintiendo)
        $vote = $data['vote'] ?? null;
        if ($vote === null) {
            return [
                'success' => false,
                'message' => 'No se envió voto',
            ];
        }

        // Registrar acción del jugador
        $playerManager->setPlayerAction($player->id, [
            'type' => 'vote',
            'vote' => $vote,
            'timestamp' => now()->toDateTimeString(),
        ]);

        // Bloquear jugador (ya votó)
        $playerManager->lockPlayer($player->id, $match, $player, ['vote' => $vote]);
        $this->savePlayerManager($match, $playerManager);

        // LOG DETALLADO: Estado después de votar
        $votantes = $playerManager->getPlayersWithRoundRole('votante');
        $lockedPlayers = $playerManager->getLockedPlayers();
        $totalVotantes = count($votantes);
        $votosRecibidos = count($lockedPlayers);
        $faltanPorVotar = $totalVotantes - $votosRecibidos;
        $isLocked = $playerManager->isPlayerLocked($player->id);

        Log::info("🗳️ [VOTO RECIBIDO]", [
            'jugador' => $player->name,
            'player_id' => $player->id,
            'voto' => $vote ? 'VERDADERO' : 'FALSO',
            'estado_bloqueado' => $isLocked ? '🔒 LOCKED' : '🔓 UNLOCKED',
            'votos_recibidos' => $votosRecibidos,
            'total_votantes' => $totalVotantes,
            'faltan_por_votar' => $faltanPorVotar,
            'jugadores_que_votaron' => $lockedPlayers,
            'match_id' => $match->id,
        ]);

        // Verificar si todos los votantes ya votaron (con protección contra concurrencia)
        $lockKey = "game:match:{$match->id}:vote-check";

        return \Illuminate\Support\Facades\Cache::lock($lockKey, 5)->block(3, function() use ($match, $player, $playerManager) {
            // Recargar match desde BD para obtener la versión más actualizada
            $match->refresh();
            $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);

            $votantes = $playerManager->getPlayersWithRoundRole('votante');
            $lockedPlayers = $playerManager->getLockedPlayers();

            Log::info("[Mentiroso] Vote count check", [
                'match_id' => $match->id,
                'player_id' => $player->id,
                'locked_count' => count($lockedPlayers),
                'votantes_count' => count($votantes),
                'locked_players' => $lockedPlayers
            ]);

            if (count($lockedPlayers) >= count($votantes)) {
                Log::info("[Mentiroso] All players voted, signaling round end", [
                    'match_id' => $match->id,
                    'locked_count' => count($lockedPlayers),
                    'votantes_count' => count($votantes)
                ]);

                // NO marcar _completing_round aquí
                // Dejar que endCurrentRound() lo maneje con su propio lock
                return [
                    'success' => true,
                    'force_end' => true,  // BaseGameEngine espera este flag
                    'end_reason' => 'all_players_voted',
                ];
            }

            return [
                'success' => true,
                'should_end_turn' => false,
            ];
        });
    }

    /**
     * Override: Finalizar ronda actual con lógica específica de Mentiroso.
     *
     * Mentiroso NECESITA sobrescribir este método porque:
     * 1. Requiere lock de concurrencia (múltiples notificaciones de timer simultáneas)
     * 2. Usa flag `_completing_round` para prevenir ejecuciones duplicadas
     * 3. Necesita refresh del match para obtener votos más recientes de BD
     *
     * NOTA: PhaseManager ahora se autogestion mediante listener
     * (CancelPhaseManagerTimersOnRoundEnd) que escucha RoundEndedEvent
     * y cancela sus timers automáticamente.
     *
     * Finalmente llama a completeRound() heredado que maneja el flujo estándar:
     * - Llamar getRoundResults() para obtener datos específicos del juego
     * - Emitir RoundEndedEvent (que triggerea el listener de PhaseManager)
     * - Verificar si hay más rondas
     * - Llamar handleNewRound() o finalize()
     */
    public function endCurrentRound(GameMatch $match): void
    {
        $lockKey = "game:match:{$match->id}:end-round";

        \Illuminate\Support\Facades\Cache::lock($lockKey, 5)->block(3, function() use ($match) {
            // Verificar si ya se completó
            $match->refresh();
            $gameState = $match->game_state;

            if (isset($gameState['_completing_round'])) {
                Log::info("[Mentiroso] Round already completing, skipping endCurrentRound", [
                    'match_id' => $match->id
                ]);
                return;
            }

            // Marcar como completando
            $gameState['_completing_round'] = true;
            $match->game_state = $gameState;
            $match->save();

            Log::info("[Mentiroso] Ending current round", ['match_id' => $match->id]);

            // CRÍTICO: Refrescar match para obtener los votos más recientes
            // Los votos se guardaron en processVote() pero necesitamos la última versión
            $match->refresh();

            // Obtener resultados de todos los jugadores
            $results = $this->getRoundResults($match);

            // Llamar a completeRound() que maneja el flow completo
            $this->completeRound($match, $results);

            Log::info("[Mentiroso] Round completed", [
                'match_id' => $match->id,
                'results' => $results
            ]);
        });
    }

    /**
     * Obtener resultados de la ronda actual (Método abstracto de BaseGameEngine).
     *
     * Formatea los datos específicos de Mentiroso:
     * - Frase actual (text + veracidad)
     * - ID del orador
     * - Lista de votos (player_id, vote, is_correct)
     * - Estadísticas de votos (correctos/incorrectos)
     * - Si el orador engañó a la mayoría
     *
     * IMPORTANTE: Este método también calcula y otorga puntos:
     * - Votantes: puntos por voto correcto/incorrecto
     * - Orador: puntos por engañar a mayoría/todos
     *
     * BaseGameEngine usa este método para:
     * - Emitir RoundEndedEvent con los resultados
     * - Guardar historial de rondas
     */
    protected function getRoundResults(GameMatch $match): array
    {
        $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);
        $allActions = $playerManager->getAllActions();
        $currentStatement = $this->getCurrentStatement($match);
        $currentOradorId = $this->getCurrentOradorId($match);

        $votes = [];
        $correctVotes = 0;
        $incorrectVotes = 0;

        // Procesar votos
        foreach ($allActions as $playerId => $action) {
            if ($action['type'] === 'vote' && $playerId !== $currentOradorId) {
                $vote = $action['vote'];
                $isCorrect = ($vote === $currentStatement['is_true']);

                $votes[] = [
                    'player_id' => $playerId,
                    'vote' => $vote,
                    'is_correct' => $isCorrect,
                ];

                if ($isCorrect) {
                    $correctVotes++;
                    // Sumar puntos al votante
                    $playerManager->awardPoints($playerId, 'voter_correct', [], $match);
                } else {
                    $incorrectVotes++;
                    $playerManager->awardPoints($playerId, 'voter_incorrect', [], $match);
                }
            }
        }

        // Calcular puntos del orador
        $totalVotes = $correctVotes + $incorrectVotes;
        $deceived = $incorrectVotes;

        if ($totalVotes > 0) {
            if ($deceived === $totalVotes) {
                // Engañó a todos (bonus)
                $playerManager->awardPoints($currentOradorId, 'orador_deceives_all', [], $match);
            } elseif ($deceived > ($totalVotes / 2)) {
                // Engañó a la mayoría
                $playerManager->awardPoints($currentOradorId, 'orador_deceives_majority', [], $match);
            }
        }

        $this->savePlayerManager($match, $playerManager);

        return [
            'statement' => $currentStatement,
            'orador_id' => $currentOradorId,
            'votes' => $votes,
            'correct_votes' => $correctVotes,
            'incorrect_votes' => $incorrectVotes,
            'orador_deceived_majority' => $deceived > ($totalVotes / 2),
        ];
    }

    /**
     * Filtrar game_state para remover información sensible antes de broadcast
     *
     * En Mentiroso, la veracidad de la frase NO debe enviarse a los votantes
     * en eventos públicos. Solo el orador la recibe vía canal privado.
     */
    protected function filterGameStateForBroadcast(array $gameState, \App\Models\GameMatch $match): array
    {
        $filtered = $gameState;

        // Remover la veracidad de la frase (is_true) para que solo el orador la vea
        if (isset($filtered['current_statement']['is_true'])) {
            unset($filtered['current_statement']['is_true']);
        }

        return $filtered;
    }

    // ========================================================================
    // GESTIÓN DE FASES
    // ========================================================================

    /**
     * Obtener o crear PhaseManager para la partida actual
     */
    private function getPhaseManager(GameMatch $match): ?PhaseManager
    {
        $phaseState = $match->game_state['phase_manager'] ?? null;

        if (!$phaseState) {
            return null;
        }

        $phaseManager = PhaseManager::fromArray($phaseState);

        // Conectar TimerService (necesario para timing automático)
        try {
            $timerService = $this->getTimerService($match);
            $phaseManager->setTimerService($timerService);
        } catch (\RuntimeException $e) {
            Log::warning("[Mentiroso] TimerService not available for PhaseManager", [
                'error' => $e->getMessage()
            ]);
        }

        // Re-registrar callbacks (no se pueden serializar)
        $this->registerPhaseCallbacks($phaseManager, $match);

        return $phaseManager;
    }

    /**
     * Registrar callbacks para las fases
     */
    private function registerPhaseCallbacks(PhaseManager $phaseManager, GameMatch $match): void
    {
        $phaseManager->onPhaseExpired('preparation', function () use ($match) {
            $this->onPhaseExpired($match, 'preparation');
        });

        $phaseManager->onPhaseExpired('persuasion', function () use ($match) {
            $this->onPhaseExpired($match, 'persuasion');
        });

        $phaseManager->onPhaseExpired('voting', function () use ($match) {
            $this->onPhaseExpired($match, 'voting');
        });
    }

    /**
     * Guardar PhaseManager en el estado del juego
     */
    private function savePhaseManager(GameMatch $match, PhaseManager $phaseManager): void
    {
        $gameState = $match->game_state;
        $gameState['phase_manager'] = $phaseManager->toArray();
        $match->game_state = $gameState;
        $match->save();

        // Guardar TimerService si está disponible (puede haber sido modificado)
        $timerService = $phaseManager->getTimerService();
        if ($timerService) {
            $this->saveTimerService($match, $timerService);
        }
    }

    /**
     * Crear PhaseManager con las fases desde config
     */
    private function createPhaseManager(GameMatch $match): PhaseManager
    {
        $gameConfig = $this->getGameConfig();
        $timing = $gameConfig['timing'] ?? [];

        $phases = [
            [
                'name' => 'preparation',
                'duration' => $timing['preparation']['duration'] ?? 15
            ],
            [
                'name' => 'persuasion',
                'duration' => $timing['persuasion']['duration'] ?? 30
            ],
            [
                'name' => 'voting',
                'duration' => $timing['voting']['duration'] ?? 10
            ],
        ];

        $phaseManager = new PhaseManager($phases);

        // Conectar TimerService (necesario para timing automático)
        try {
            $timerService = $this->getTimerService($match);
            $phaseManager->setTimerService($timerService);
        } catch (\RuntimeException $e) {
            Log::warning("[Mentiroso] TimerService not available for PhaseManager", [
                'error' => $e->getMessage()
            ]);
        }

        // Registrar callbacks
        $this->registerPhaseCallbacks($phaseManager, $match);

        return $phaseManager;
    }


    /**
     * Callback cuando expira el timer de una fase
     */
    private function onPhaseExpired(GameMatch $match, string $phaseName): void
    {
        Log::info("[Mentiroso] Phase expired", [
            'match_id' => $match->id,
            'phase' => $phaseName
        ]);

        // Recargar match para evitar stale data
        $match->refresh();

        if ($phaseName === 'preparation' || $phaseName === 'persuasion') {
            // Avanzar a la siguiente fase
            $this->advanceToNextPhase($match);
        } elseif ($phaseName === 'voting') {
            // Timer de votación expiró, terminar ronda
            $this->endCurrentRound($match);
        }
    }

    /**
     * Avanzar a la siguiente fase
     */
    private function advanceToNextPhase(GameMatch $match): void
    {
        $phaseManager = $this->getPhaseManager($match);

        if (!$phaseManager) {
            Log::error("[Mentiroso] PhaseManager not found");
            return;
        }

        $previousPhase = $phaseManager->getCurrentPhaseName();
        $phaseInfo = $phaseManager->nextPhase();
        $newPhase = $phaseInfo['phase_name'];

        // Actualizar current_phase en game_state
        $gameState = $match->game_state;
        $gameState['current_phase'] = $newPhase;
        $match->game_state = $gameState;
        $match->save();

        // Guardar PhaseManager actualizado
        $this->savePhaseManager($match, $phaseManager);

        Log::info("[Mentiroso] Phase advanced", [
            'match_id' => $match->id,
            'previous_phase' => $previousPhase,
            'new_phase' => $newPhase,
            'duration' => $phaseInfo['duration']
        ]);

        // Emitir evento de cambio de fase
        $timing = [
            'server_time' => now()->timestamp,
            'duration' => $phaseInfo['duration']
        ];

        $event = new PhaseChangedEvent(
            $match,
            $newPhase,
            $previousPhase,
            [
                'phase' => $newPhase,
                'game_state' => $this->filterGameStateForBroadcast($match->game_state, $match),
                'timing' => $timing
            ]
        );

        Log::info("[Mentiroso] Emitting PhaseChangedEvent", [
            'match_id' => $match->id,
            'room_code' => $match->room->code,
            'new_phase' => $newPhase,
            'previous_phase' => $previousPhase,
            'timing' => $timing
        ]);

        event($event);
    }

    // ========================================================================
    // GESTIÓN DE FRASES
    // ========================================================================

    /**
     * Seleccionar frases aleatorias
     */
    private function selectStatements(array $statements, int $totalRounds): array
    {
        shuffle($statements);
        return array_slice($statements, 0, $totalRounds);
    }

    /**
     * Cargar la siguiente frase basándose en la ronda actual
     */
    private function loadNextStatement(GameMatch $match): array
    {
        $roundManager = $this->getRoundManager($match);
        $currentRound = $roundManager->getCurrentRound();

        $statement = $this->getStatementByRound($match, $currentRound);

        $this->setCurrentStatement($match, $statement);

        return $statement;
    }

    /**
     * Obtener frase por número de ronda
     */
    private function getStatementByRound(GameMatch $match, int $roundNumber): array
    {
        $statements = $match->game_state['statements'] ?? [];
        $statementIndex = $roundNumber - 1; // Convertir a índice 0-based

        if (!isset($statements[$statementIndex])) {
            throw new \RuntimeException("No hay frase para la ronda {$roundNumber}");
        }

        return $statements[$statementIndex];
    }

    /**
     * Establecer la frase actual en el estado del juego
     */
    private function setCurrentStatement(GameMatch $match, array $statement): void
    {
        $gameState = $match->game_state;
        $gameState['current_statement'] = $statement;
        $match->game_state = $gameState;
        $match->save();
    }

    /**
     * Obtener la frase actual
     */
    private function getCurrentStatement(GameMatch $match): ?array
    {
        return $match->game_state['current_statement'] ?? null;
    }

    // ========================================================================
    // GESTIÓN DE ORADORES
    // ========================================================================

    /**
     * Rotar al siguiente orador
     */
    private function rotateOrador(GameMatch $match): void
    {
        $gameState = $match->game_state;
        $rotation = $gameState['orador_rotation'] ?? [];
        $currentIndex = $gameState['current_orador_index'] ?? 0;

        // Avanzar al siguiente orador
        $nextIndex = ($currentIndex + 1) % count($rotation);

        $gameState['current_orador_index'] = $nextIndex;
        $match->game_state = $gameState;
        $match->save();

        Log::info("[Mentiroso] Orador rotated", [
            'match_id' => $match->id,
            'previous_index' => $currentIndex,
            'new_index' => $nextIndex,
            'new_orador_id' => $rotation[$nextIndex]
        ]);
    }

    /**
     * Obtener el ID del orador actual
     */
    private function getCurrentOradorId(GameMatch $match): ?int
    {
        $rotation = $match->game_state['orador_rotation'] ?? [];
        $currentIndex = $match->game_state['current_orador_index'] ?? 0;

        return $rotation[$currentIndex] ?? null;
    }

    /**
     * Asignar roles (orador/votantes) a los jugadores
     */
    private function assignRoles(GameMatch $match): void
    {
        $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);
        $roundManager = $this->getRoundManager($match);
        $currentRound = $roundManager->getCurrentRound();

        // Obtener orden de rotación de oradores
        $oradorRotation = $match->game_state['orador_rotation'] ?? [];
        $currentOradorIndex = $match->game_state['current_orador_index'] ?? 0;
        $currentOradorId = $oradorRotation[$currentOradorIndex] ?? null;

        Log::info("[Mentiroso] Assigning roles", [
            'current_round' => $currentRound,
            'current_orador_index' => $currentOradorIndex,
            'current_orador_id' => $currentOradorId,
        ]);

        // Limpiar roles anteriores
        foreach ($oradorRotation as $playerId) {
            $playerManager->removeRoundRole($playerId);
        }

        // Asignar orador actual
        $playerManager->assignRoundRole($currentOradorId, 'orador');

        // Asignar votantes (todos los demás)
        foreach ($oradorRotation as $playerId) {
            if ($playerId !== $currentOradorId) {
                $playerManager->assignRoundRole($playerId, 'votante');
            }
        }

        // Guardar PlayerManager actualizado
        $this->savePlayerManager($match, $playerManager);

        Log::info("[Mentiroso] Roles assigned", [
            'round' => $currentRound,
            'orador_id' => $currentOradorId,
            'votante_ids' => array_filter($oradorRotation, fn($id) => $id !== $currentOradorId),
        ]);
    }

    // ========================================================================
    // HOOKS DE DESCONEXIÓN/RECONEXIÓN
    // ========================================================================

    /**
     * Hook: Ejecutado ANTES de pausar el juego por desconexión de jugador.
     *
     * Mentiroso necesita:
     * - Cancelar timers de PhaseManager (ahora lo hace el listener automáticamente)
     * - Marcar jugadores como bloqueados (lo hace BaseGameEngine automáticamente)
     */
    protected function beforePlayerDisconnectedPause(GameMatch $match, Player $player): void
    {
        Log::info("[Mentiroso] Player disconnected - game will pause", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'current_phase' => $match->game_state['current_phase'] ?? 'unknown'
        ]);

        // PhaseManager timers se cancelarán automáticamente por GamePausedEvent listener
        // PlayerManager bloqueará jugadores automáticamente
    }

    /**
     * Hook: Ejecutado DESPUÉS de que un jugador se reconecta.
     *
     * Política de Mentiroso: RESETEAR la ronda actual porque es mejor volver a empezar.
     * - Descartar votos de la ronda actual
     * - Resetear PhaseManager (volver a preparation)
     * - Reiniciar ronda desde el principio
     */
    protected function afterPlayerReconnected(GameMatch $match, Player $player): void
    {
        Log::info("[Mentiroso] Player reconnected - resetting current round", [
            'match_id' => $match->id,
            'player_id' => $player->id
        ]);

        // 1. Limpiar votos y acciones de la ronda actual
        $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);
        $playerManager->reset($match); // Desbloquea y limpia acciones
        $this->savePlayerManager($match, $playerManager);

        // 2. Cancelar PhaseManager existente
        $phaseManager = $this->getPhaseManager($match);
        if ($phaseManager) {
            $phaseManager->cancelAllTimers();
        }

        // 3. Limpiar flag de completado de ronda
        $gameState = $match->game_state;
        unset($gameState['_completing_round']);
        $match->game_state = $gameState;
        $match->save();

        // 4. Reiniciar la ronda actual (sin avanzar contador)
        // handleNewRound con advanceRound=false mantiene la ronda actual pero reinicia todo
        $this->handleNewRound($match, advanceRound: false);

        Log::info("[Mentiroso] Round reset after reconnection", [
            'match_id' => $match->id
        ]);
    }

    // ========================================================================
    // MÉTODOS OBLIGATORIOS DE BaseGameEngine
    // ========================================================================

    /**
     * Verificar condición de victoria
     */
    public function checkWinCondition(GameMatch $match): ?Player
    {
        // En Mentiroso, el ganador se determina al final por puntos
        return null;
    }

    /**
     * Obtener estado del juego para un jugador
     */
    public function getGameStateForPlayer(GameMatch $match, Player $player): array
    {
        // Verificar si el timer de fase ha expirado
        $this->checkPhaseTimerExpiration($match);

        return [
            'phase' => $match->game_state['phase'] ?? 'unknown',
            'message' => 'El juego ha empezado',
        ];
    }

    /**
     * Manejar expiración de timers (preparation, persuasion, voting).
     *
     * Este método es llamado cuando el frontend notifica que un timer expiró.
     * Verificamos con PhaseManager y ejecutamos callbacks automáticamente.
     *
     * @param GameMatch $match
     * @param string $timerType Tipo de timer: 'preparation', 'persuasion', 'voting'
     * @return array Resultado de la acción
     */
    public function onTimerExpired(GameMatch $match, string $timerType): array
    {
        Log::info("[Mentiroso] Timer expired notification received", [
            'match_id' => $match->id,
            'timer_type' => $timerType
        ]);

        // Obtener PhaseManager
        $phaseManager = $this->getPhaseManager($match);

        if (!$phaseManager) {
            Log::warning("[Mentiroso] PhaseManager not found, falling back to direct handling");

            // Fallback: manejar manualmente
            switch ($timerType) {
                case 'preparation':
                case 'persuasion':
                    $this->advanceToNextPhase($match);
                    return ['action' => 'phase_advanced_fallback', 'from' => $timerType];

                case 'voting':
                    $this->endCurrentRound($match);
                    return ['action' => 'round_ended_fallback'];

                default:
                    return ['action' => 'ignored', 'reason' => 'unknown_timer_type'];
            }
        }

        // Verificar si el timer realmente expiró en el backend
        if (!$phaseManager->isTimeExpired()) {
            Log::debug("[Mentiroso] Timer not expired yet in backend", [
                'timer_type' => $timerType,
                'remaining_time' => $phaseManager->getTimingInfo()['time_remaining'] ?? 'unknown'
            ]);
            return ['action' => 'ignored', 'reason' => 'timer_not_expired'];
        }

        // Ejecutar callback registrado para la fase actual
        // Los callbacks ya están registrados en registerPhaseCallbacks()
        $callbackExecuted = $phaseManager->triggerPhaseExpired();

        if ($callbackExecuted) {
            Log::info("[Mentiroso] Phase callback executed via PhaseManager", [
                'phase' => $timerType
            ]);
            return ['action' => 'callback_executed', 'phase' => $timerType];
        }

        Log::warning("[Mentiroso] No callback registered for phase: {$timerType}");
        return ['action' => 'no_callback', 'phase' => $timerType];
    }

    /**
     * Manejar desconexión de jugador
     */
    public function onPlayerDisconnected(GameMatch $match, Player $player): void
    {
        Log::info("[Mentiroso] Player disconnected", [
            'match_id' => $match->id,
            'player_id' => $player->id
        ]);

        // Llamar al comportamiento por defecto de BaseGameEngine
        // que pausa el juego y emite PlayerDisconnectedEvent
        parent::onPlayerDisconnected($match, $player);
    }

    /**
     * Manejar reconexión de jugador
     */
    public function onPlayerReconnected(GameMatch $match, Player $player): void
    {
        Log::info("[Mentiroso] Player reconnected", [
            'match_id' => $match->id,
            'player_id' => $player->id
        ]);

        // Llamar al comportamiento por defecto de BaseGameEngine
        // que reanuda el juego y emite PlayerReconnectedEvent
        parent::onPlayerReconnected($match, $player);
    }

    // getGameConfig() y getFinalScores() se heredan automáticamente de BaseGameEngine
}
