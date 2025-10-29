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
     * Hook: Ejecutado DESPUÉS de emitir RoundStartedEvent.
     *
     * Aquí emitimos PhaseChangedEvent para la fase inicial (preparation) con timing.
     * Esto permite que el frontend primero procese RoundStartedEvent (actualizar UI, round info)
     * y LUEGO reciba PhaseChangedEvent para iniciar el timer de la fase.
     */
    protected function onRoundStarted(GameMatch $match, int $currentRound, int $totalRounds): void
    {
        $phaseManager = $this->getPhaseManager($match);

        if (!$phaseManager) {
            Log::error("[Mentiroso] PhaseManager NOT FOUND in onRoundStarted", [
                'match_id' => $match->id
            ]);
            return;
        }

        // NOTA: RoundManager ya configuró PhaseManager (setMatch + startTurnTimer)
        // en emitRoundStartedEvent(). Aquí solo emitimos PhaseChangedEvent para el frontend.

        $currentPhase = $phaseManager->getCurrentPhaseName();
        $timingInfo = $phaseManager->getTimingInfo();

        $timing = [
            'server_time' => now()->timestamp,
            'duration' => $timingInfo['delay'] ?? 0
        ];

        Log::info("🚀🚀🚀 [EMIT] PhaseChangedEvent FROM onRoundStarted", [
            'match_id' => $match->id,
            'current_round' => $currentRound,
            'new_phase' => $currentPhase,
            'previous_phase' => '',
            'duration' => $timing['duration'],
            'location' => 'onRoundStarted'
        ]);

        event(new \App\Events\Game\PhaseChangedEvent(
            $match,
            $currentPhase,
            '',  // No previous phase (nueva ronda)
            [
                'phase' => $currentPhase,
                'game_state' => $this->filterGameStateForBroadcast($match->game_state, $match),
                'timing' => $timing
            ]
        ));
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
        $roundManager = $this->getRoundManager($match);
        $currentRound = $roundManager->getCurrentRound();

        Log::info("[Mentiroso] Preparing round data", [
            'match_id' => $match->id,
            'current_round' => $currentRound
        ]);

        // 🛡️ DEFENSIVE CHECK: Solo preparar ronda si NO hay statement actual
        // Esto previene rotar orador múltiples veces si onRoundStarting() se llama por error
        $gameState = $match->game_state;
        if (isset($gameState['current_statement']) && $gameState['current_statement'] !== null) {
            Log::warning("[Mentiroso] Round already started, skipping onRoundStarting", [
                'match_id' => $match->id,
                'current_round' => $currentRound,
                'current_statement' => $gameState['current_statement']
            ]);
            return; // Ya se preparó esta ronda, no hacer nada
        }

        // 1. Rotar orador SOLO si no es la primera ronda
        // La primera ronda ya tiene current_orador_index=0 establecido en onGameStart()
        if ($currentRound > 1) {
            $this->rotateOrador($match);
        } else {
            Log::info("[Mentiroso] Skipping orador rotation for first round", [
                'match_id' => $match->id,
                'current_round' => $currentRound
            ]);
        }

        // 2. Cargar siguiente frase
        $statement = $this->loadNextStatement($match);

        // 3. Asignar roles (orador/votantes)
        $this->assignRoles($match);

        // 4. Obtener PhaseManager del RoundManager (ya inicializado por BaseGameEngine)
        $roundManager = $this->getRoundManager($match);
        $phaseManager = $roundManager->getTurnManager(); // PhaseManager

        if (!$phaseManager) {
            Log::error("[Mentiroso] PhaseManager NOT FOUND - should have been created by RoundManager", [
                'match_id' => $match->id
            ]);
            return;
        }

        // NOTA: La configuración del PhaseManager (setMatch + startTurnTimer) se hace
        // en onRoundStarted(), que se ejecuta DESPUÉS de RoundStartedEvent.
        // Esto garantiza que el timer se crea con el match ya configurado.

        // Establecer fase inicial
        $currentPhase = $phaseManager->getCurrentPhaseName();
        $gameState = $match->game_state;
        $gameState['current_phase'] = $currentPhase;
        $match->game_state = $gameState;
        $match->save();

        Log::info("[Mentiroso] New round started", [
            'match_id' => $match->id,
            'statement' => $statement['text'],
            'orador_id' => $this->getCurrentOradorId($match),
            'phase' => $currentPhase
        ]);

        // Emitir evento personalizado con la frase (SOLO al orador, canal privado)
        $currentOradorId = $this->getCurrentOradorId($match);
        $orador = Player::find($currentOradorId);
        if ($orador) {
            event(new \App\Events\Mentiroso\StatementRevealedEvent(
                $match,
                $orador,
                $statement['text'],
                $statement['is_true'],
                $roundManager->getCurrentRound()
            ));
        }

        // NOTA: NO emitimos PhaseChangedEvent aquí porque onRoundStarting() se ejecuta
        // ANTES de que RoundStartedEvent sea emitido. En su lugar, usamos onRoundStarted()
        // que se ejecuta DESPUÉS de RoundStartedEvent.
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
        // ⚠️ IMPORTANTE: Usar el MISMO lock que endCurrentRound() para evitar race conditions
        // Si el timer expira mientras se procesa un voto, el voto se procesa primero
        $lockKey = "game:match:{$match->id}:end-round";

        return \Illuminate\Support\Facades\Cache::lock($lockKey, 10)->block(8, function() use ($match, $player, $playerManager) {
            // Recargar match desde BD para obtener la versión más actualizada
            $match->refresh();
            $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);

            // 🛡️ DEFENSIVE CHECK: Verificar que la ronda no haya terminado mientras esperábamos el lock
            // Si current_statement es null, significa que endCurrentRound() ya ejecutó
            if (!isset($match->game_state['current_statement']) || $match->game_state['current_statement'] === null) {
                Log::warning("[Mentiroso] Vote arrived after round ended, ignoring", [
                    'match_id' => $match->id,
                    'player_id' => $player->id
                ]);

                return [
                    'success' => false,
                    'message' => 'La ronda ya terminó',
                ];
            }

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

                return [
                    'success' => true,
                    'force_end' => true,  // BaseGameEngine espera este flag
                    'end_reason' => 'all_players_voted',
                ];
            }

            return [
                'success' => true,
                'force_end' => false,
            ];
        });
    }

    /**
     * Override: Finalizar ronda actual con lógica específica de Mentiroso.
     *
     * Mentiroso NECESITA sobrescribir este método porque:
     * 1. Requiere lock de concurrencia (múltiples notificaciones de timer simultáneas)
     * 2. Necesita refresh del match para obtener votos más recientes de BD
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
        Log::info("🔵🔵🔵 [ENTRY] endCurrentRound", [
            'match_id' => $match->id,
            'location' => 'endCurrentRound'
        ]);

        $lockKey = "game:match:{$match->id}:end-round";

        \Illuminate\Support\Facades\Cache::lock($lockKey, 5)->block(3, function() use ($match) {
            // CRÍTICO: Refrescar match para obtener los votos más recientes
            // Los votos se guardaron en processVote() pero necesitamos la última versión
            $match->refresh();

            Log::info("[Mentiroso] Ending current round", ['match_id' => $match->id]);

            // Obtener resultados de todos los jugadores
            $results = $this->getRoundResults($match);

            // ✅ IMPORTANTE: Limpiar current_statement ANTES de completeRound()
            // Esto permite que onRoundStarting() de la siguiente ronda cargue nueva frase
            $gameState = $match->game_state;
            $gameState['current_statement'] = null;
            $match->game_state = $gameState;
            $match->save();

            Log::info("[Mentiroso] Current statement cleared for next round", [
                'match_id' => $match->id
            ]);

            // Llamar a completeRound() que maneja el flow completo
            $this->completeRound($match, $results);

            Log::info("[Mentiroso] Round completed", [
                'match_id' => $match->id,
                'results' => $results
            ]);
        });

        Log::info("🔴🔴🔴 [EXIT] endCurrentRound", [
            'match_id' => $match->id,
            'location' => 'endCurrentRound'
        ]);
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
     * Obtener PhaseManager desde RoundManager
     */
    private function getPhaseManager(GameMatch $match): ?PhaseManager
    {
        $roundManager = $this->getRoundManager($match);
        $phaseManager = $roundManager->getTurnManager(); // PhaseManager

        if ($phaseManager) {
            // Configurar match para emitir eventos
            $phaseManager->setMatch($match);

            // NOTA: Ya NO re-registramos callbacks.
            // El sistema ahora es event-driven: PhaseTimerExpiredEvent → Listener → phase handlers
        }

        return $phaseManager;
    }

    // NOTA: registerPhaseCallbacks() y onPhaseExpired() fueron eliminados.
    // Ahora usamos event-driven architecture:
    // PhaseManager emite PhaseTimerExpiredEvent → HandlePhaseTimerExpired listener → phase handlers

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

        // nextPhase() avanza la fase Y crea el nuevo timer automáticamente
        $phaseInfo = $phaseManager->nextPhase();
        $newPhase = $phaseInfo['phase_name'];

        // Actualizar game_state con la nueva fase
        $gameState = $match->game_state;
        $gameState['current_phase'] = $newPhase;

        // Guardar RoundManager (que contiene PhaseManager actualizado)
        $roundManager = $this->getRoundManager($match);
        $this->saveRoundManager($match, $roundManager);

        // CRÍTICO: Guardar también el timer_system con el nuevo timer
        $timerService = $phaseManager->getTimerService();
        if ($timerService) {
            $timerData = $timerService->toArray();
            $gameState['timer_system'] = $timerData['timer_system'] ?? [];
        }

        $match->game_state = $gameState;
        $match->save();

        // Emitir evento de cambio de fase para notificar al frontend
        $timing = [
            'server_time' => now()->timestamp,
            'duration' => $phaseInfo['duration']
        ];

        event(new PhaseChangedEvent(
            $match,
            $newPhase,
            $previousPhase,
            [
                'phase' => $newPhase,
                'game_state' => $this->filterGameStateForBroadcast($match->game_state, $match),
                'timing' => $timing
            ]
        ));
    }

    // ========================================================================
    // PHASE HANDLERS - Event-Driven Architecture
    // ========================================================================

    /**
     * Handler: Fase de preparación terminada
     *
     * Se ejecuta cuando expira el timer de la fase "preparation".
     * Avanza automáticamente a la siguiente fase (persuasion).
     */
    public function onPreparationEnd(GameMatch $match): void
    {
        Log::info("[Mentiroso] Preparation phase ended", [
            'match_id' => $match->id
        ]);

        // Avanzar a la siguiente fase (persuasion)
        $this->advanceToNextPhase($match);
    }

    /**
     * Handler: Fase de persuasión terminada
     *
     * Se ejecuta cuando expira el timer de la fase "persuasion".
     * Avanza automáticamente a la siguiente fase (voting).
     */
    public function onPersuasionEnd(GameMatch $match): void
    {
        Log::info("[Mentiroso] Persuasion phase ended", [
            'match_id' => $match->id
        ]);

        // Avanzar a la siguiente fase (voting)
        $this->advanceToNextPhase($match);
    }

    /**
     * Handler: Fase de votación terminada
     *
     * Se ejecuta cuando expira el timer de la fase "voting".
     * Termina la ronda actual y procesa los resultados.
     */
    public function onVotingEnd(GameMatch $match): void
    {
        Log::info("[Mentiroso] Voting phase ended - ending round", [
            'match_id' => $match->id
        ]);

        // Terminar la ronda actual
        $this->endCurrentRound($match);
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

        // 3. Reiniciar la ronda actual (sin avanzar contador)
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
        return [
            'phase' => $match->game_state['phase'] ?? 'unknown',
            'message' => 'El juego ha empezado',
        ];
    }

    /**
     * Manejar expiración de timer - Event-Driven Architecture.
     *
     * Este método es llamado por el frontend cuando el countdown llega a 0.
     * Simplemente le dice a TimerService que emita el evento configurado.
     *
     * Flujo:
     * 1. Frontend notifica timer expiró
     * 2. TimerService emite PhaseTimerExpiredEvent (con datos del timer)
     * 3. HandlePhaseTimerExpired listener recibe evento
     * 4. Listener llama a onPreparationEnd/onPersuasionEnd/onVotingEnd
     *
     * @param GameMatch $match
     * @param string $timerType Nombre de la fase (preparation, persuasion, voting)
     * @return array Resultado
     */
    public function onTimerExpired(GameMatch $match, string $timerType): array
    {
        Log::info("[Mentiroso] Timer expired notification received", [
            'match_id' => $match->id,
            'timer_type' => $timerType
        ]);

        // Obtener TimerService del PhaseManager
        $phaseManager = $this->getPhaseManager($match);
        if (!$phaseManager) {
            return ['success' => false, 'message' => 'PhaseManager not found'];
        }

        $timerService = $phaseManager->getTimerService();
        if (!$timerService) {
            return ['success' => false, 'message' => 'TimerService not found'];
        }

        // 🛡️ DEFENSIVE CHECK: Verificar que estamos en la fase correcta
        $currentPhase = $phaseManager->getCurrentPhaseName();
        if ($currentPhase !== $timerType) {
            Log::info("[Mentiroso] onTimerExpired IGNORED - phase already changed", [
                'match_id' => $match->id,
                'timer_type' => $timerType,
                'current_phase' => $currentPhase
            ]);
            return ['success' => false, 'message' => 'Phase already changed', 'current' => $currentPhase];
        }

        // 🛡️ LOCK: Solo procesar una vez por fase
        $roundManager = $this->getRoundManager($match);
        $currentRound = $roundManager->getCurrentRound();
        $lockKey = "game:match:{$match->id}:round:{$currentRound}:phase:{$timerType}";

        try {
            $result = \Illuminate\Support\Facades\Cache::lock($lockKey, 10)->block(3, function() use ($timerService, $timerType) {
                // Emitir el evento configurado en el timer
                // El TimerService lee eventToEmit + eventData y emite PhaseTimerExpiredEvent
                $emitted = $timerService->emitTimerExpiredEvent($timerType);

                if ($emitted) {
                    return ['success' => true, 'message' => 'Event emitted', 'phase' => $timerType];
                }

                return ['success' => false, 'message' => 'Timer not found or no event configured'];
            });

            return $result;
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            return ['success' => false, 'message' => 'Already being processed'];
        }
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
