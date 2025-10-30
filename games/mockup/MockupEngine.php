<?php

namespace Games\Mockup;

use App\Contracts\BaseGameEngine;
use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

/**
 * MockupEngine - Motor de juego mockup para testing
 *
 * Este engine cumple TODAS las convenciones de arquitectura y sirve como:
 * - Modelo de referencia para implementar otros juegos
 * - Engine para testing sin lógica compleja
 * - Validación de eventos y estados del sistema
 *
 * ARQUITECTURA:
 * - Implementa BaseGameEngine correctamente
 * - Usa PlayerStateManager para locks
 * - Usa RoundManager para rondas
 * - Usa ScoreManager para puntuación
 * - Emite eventos genéricos correctamente
 *
 * FLUJO:
 * 1. initialize() - Configurar el juego (una sola vez)
 * 2. startGame() - Iniciar juego y primera ronda
 * 3. processRoundAction() - Procesar acciones de jugadores
 * 4. endCurrentRound() - Finalizar ronda actual
 * 5. startNewRound() - Iniciar siguiente ronda
 * 6. finalize() - Terminar juego y calcular ranking
 */
class MockupEngine extends BaseGameEngine
{
    // ========================================================================
    // MÉTODOS PÚBLICOS: GameEngineInterface
    // ========================================================================

    /**
     * Inicializar el motor del juego (FASE 1 - una sola vez).
     *
     * RESPONSABILIDAD: Guardar CONFIGURACIÓN del juego.
     * NO resetea estados - eso lo hace startGame().
     */
    public function initialize(GameMatch $match): void
    {
        Log::info("[Mockup] Initializing - FASE 1", ['match_id' => $match->id]);

        // Cargar config.json del juego
        $gameConfig = $this->getGameConfig();

        // Configuración inicial del juego
        $match->game_state = [
            '_config' => [
                'game' => 'mockup',
                'initialized_at' => now()->toDateTimeString(),
                'timing' => $gameConfig['timing'] ?? null,
                'modules' => $gameConfig['modules'] ?? [],
            ],
            'phase' => 'waiting',
            'actions' => [], // Acciones de cada ronda
        ];

        $match->save();

        // Inicializar módulos desde config.json
        $this->initializeModules($match, [
            'round_system' => [
                'total_rounds' => 3 // Mockup game: 3 rondas simples
            ],
            'scoring_system' => [
                'calculator' => new MockupScoreCalculator()
            ]
        ]);

        // Inicializar PlayerStateManager
        $playerIds = $match->players->pluck('id')->toArray();
        $playerState = new \App\Services\Modules\PlayerStateSystem\PlayerStateManager(
            playerIds: $playerIds
        );
        $this->savePlayerStateManager($match, $playerState);

        Log::info("[Mockup] Initialized successfully", [
            'match_id' => $match->id,
            'total_players' => count($playerIds),
        ]);
    }

    /**
     * Finalizar la partida (calcular ganador, ranking, etc.).
     */
    public function finalize(GameMatch $match): array
    {
        Log::info("[Mockup] Finalizing game", ['match_id' => $match->id]);

        // Obtener scores finales
        $scoreManager = $this->getScoreManager($match);
        $scores = $scoreManager->getScores();

        // Crear ranking ordenado por puntos
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

        // Determinar ganador
        $winner = !empty($ranking) ? $ranking[0]['player_id'] : null;

        // Marcar partida como terminada
        $match->game_state = array_merge($match->game_state, [
            'phase' => 'finished',
            'finished_at' => now()->toDateTimeString(),
            'final_scores' => $scores,
            'ranking' => $ranking,
            'winner' => $winner,
        ]);

        $match->save();

        // Emitir evento de juego terminado
        event(new \App\Events\Game\GameEndedEvent(
            match: $match,
            winner: $winner,
            ranking: $ranking,
            scores: $scores
        ));

        Log::info("[Mockup] Game finalized", [
            'match_id' => $match->id,
            'winner' => $winner,
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

    // ========================================================================
    // MÉTODOS PROTEGIDOS: Lógica específica del juego
    // ========================================================================

    /**
     * Lógica al iniciar el juego (después del countdown).
     *
     * Llamado por BaseGameEngine::startGame() después de resetear módulos.
     *
     * RESPONSABILIDAD: Iniciar la primera ronda del juego.
     */
    protected function onGameStart(GameMatch $match): void
    {
        Log::info("🎮 [Mockup] ===== PARTIDA INICIADA ===== onGameStart()", ['match_id' => $match->id]);

        // Actualizar fase a "playing"
        $match->game_state = array_merge($match->game_state, [
            'phase' => 'playing',
        ]);
        $match->save();

        Log::info("🎮 [Mockup] Fase actualizada a 'playing'");

        // Iniciar la primera ronda (advanceRound = false porque es la primera)
        // Esto emitirá RoundStartedEvent y PhaseChangedEvent automáticamente
        $this->handleNewRound($match, advanceRound: false);

        Log::info("🎮 [Mockup] Primera ronda iniciada - handleNewRound() completado");
    }

    /**
     * Iniciar una nueva ronda.
     *
     * Preparar el estado para la nueva ronda (desbloquear jugadores, etc.).
     */
    protected function startNewRound(GameMatch $match): void
    {
        $currentRound = $this->getRoundManager($match)->getCurrentRound();

        Log::info("[Mockup] Starting new round", [
            'match_id' => $match->id,
            'round' => $currentRound,
        ]);

        // Desbloquear todos los jugadores para la nueva ronda
        $playerState = $this->getPlayerStateManager($match);
        $playerState->reset();
        $this->savePlayerStateManager($match, $playerState);

        // Emitir evento de jugadores desbloqueados
        event(new \App\Events\Game\PlayersUnlockedEvent(
            roomCode: $match->room->code,
            fromNewRound: true
        ));

        // Limpiar acciones de la ronda anterior
        $match->game_state['actions'] = [];
        $match->game_state['phase'] = 'playing';
        $match->save();

        Log::info("[Mockup] Round started - players unlocked");
    }

    /**
     * Procesar la acción de un jugador en la ronda actual.
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
        Log::info("[Mockup] Processing action", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'action' => $data,
        ]);

        // Validar que el jugador no esté bloqueado
        $playerState = $this->getPlayerStateManager($match);

        if ($playerState->isPlayerLocked($player->id)) {
            return [
                'success' => false,
                'message' => 'Ya realizaste tu acción en esta ronda',
                'force_end' => false,
            ];
        }

        // Procesar acción (mockup: simplemente registrarla)
        $actionValue = $data['value'] ?? 'default_action';

        // Registrar acción
        $playerState->setPlayerAction($player->id, [
            'type' => 'mockup_action',
            'value' => $actionValue,
            'timestamp' => now()->toDateTimeString(),
        ]);

        // Bloquear jugador (retorna si todos están bloqueados)
        $allLocked = $playerState->lockPlayer($player->id, $match, $player);

        $this->savePlayerStateManager($match, $playerState);

        // Guardar acción en game_state
        $match->game_state['actions'][$player->id] = $actionValue;
        $match->save();

        Log::info("[Mockup] Action processed", [
            'player_id' => $player->id,
            'all_locked' => $allLocked,
        ]);

        // DECISIÓN DE FIN DE RONDA: En mockup, termina cuando todos actúan
        return [
            'success' => true,
            'player_id' => $player->id,
            'data' => ['action' => $actionValue],
            'force_end' => $allLocked, // Terminar cuando todos actuaron
            'end_reason' => $allLocked ? 'all_players_acted' : null,
        ];
    }

    /**
     * Finalizar la ronda actual y calcular resultados.
     *
     * Calcular puntos, actualizar scores, y completar la ronda.
     */
    public function endCurrentRound(GameMatch $match): void
    {
        Log::info("[Mockup] Ending current round", ['match_id' => $match->id]);

        // Obtener todas las acciones de la ronda
        $playerState = $this->getPlayerStateManager($match);
        $allActions = $playerState->getAllActions();

        // Calcular puntos (mockup: 10 puntos por participar)
        $scoreManager = $this->getScoreManager($match);

        foreach ($allActions as $playerId => $action) {
            $scoreManager->addPoints($playerId, 10);
            Log::info("[Mockup] Points awarded", [
                'player_id' => $playerId,
                'points' => 10,
            ]);
        }

        $this->saveScoreManager($match, $scoreManager);

        // Recopilar resultados de la ronda
        $scores = $scoreManager->getScores();
        $results = [
            'actions' => $allActions,
            'round_scores' => $scores,
        ];

        // Completar ronda (esto emite RoundEndedEvent y maneja timing)
        $this->completeRound($match, $results);

        Log::info("[Mockup] Round ended successfully");
    }

    /**
     * Obtener todos los resultados de jugadores en la ronda actual.
     *
     * Este método es usado por las estrategias de fin de ronda.
     */
    protected function getAllPlayerResults(GameMatch $match): array
    {
        $playerState = $this->getPlayerStateManager($match);
        return $playerState->getAllActions();
    }

    /**
     * Obtener resultados de la ronda actual (implementación abstracta requerida).
     *
     * Retorna los resultados procesados de la ronda para BaseGameEngine.
     */
    protected function getRoundResults(GameMatch $match): array
    {
        $playerState = $this->getPlayerStateManager($match);
        $allActions = $playerState->getAllActions();

        $scoreManager = $this->getScoreManager($match);
        $scores = $scoreManager->getScores();

        return [
            'actions' => $allActions,
            'scores' => $scores,
        ];
    }

    /**
     * Obtener configuración del juego desde config.json.
     */
    protected function getGameConfig(): array
    {
        $configPath = base_path('games/mockup/config.json');

        if (!file_exists($configPath)) {
            return $this->getDefaultConfig();
        }

        return json_decode(file_get_contents($configPath), true);
    }

    /**
     * Configuración por defecto si no existe config.json.
     */
    private function getDefaultConfig(): array
    {
        return [
            'timing' => [
                'round_ended' => [
                    'type' => 'countdown',
                    'seconds' => 3,
                ],
            ],
            'modules' => [
                'scoring_system' => ['enabled' => true],
                'round_system' => ['enabled' => true],
                'turn_system' => ['enabled' => false],
            ],
        ];
    }

    /**
     * Obtener scores finales (implementación específica de Mockup).
     *
     * Este método es llamado por BaseGameEngine::finalize() (Template Method).
     * Implementa la lógica específica de cómo Mockup calcula los scores finales.
     *
     * @param GameMatch $match
     * @return array Array asociativo [player_id => score]
     */
    protected function getFinalScores(GameMatch $match): array
    {
        Log::info("[Mockup] Calculating final scores", ['match_id' => $match->id]);

        $scoreManager = $this->getScoreManager($match);
        return $scoreManager->getScores();
    }

    // ========================================================================
    // MÉTODOS DE FASE: Callbacks cuando expiran las fases
    // ========================================================================

    /**
     * Callback cuando inicia la fase 2.
     */
    public function handlePhase2Started(GameMatch $match, array $phaseData): void
    {
        Log::info("🎬 [Mockup] FASE 2 INICIADA", [
            'match_id' => $match->id,
            'phase_data' => $phaseData
        ]);

        // Aquí puedes hacer lógica específica cuando comienza phase2
        // Por ejemplo: preparar datos, notificar jugadores, etc.
    }

    /**
     * Callback cuando expira la fase 1.
     *
     * Avanza a la fase 2.
     */
    public function handlePhase1Ended(GameMatch $match, array $phaseData): void
    {
        Log::info("🏁 [Mockup] FASE 1 FINALIZADA - Ejecutando callback", [
            'match_id' => $match->id,
            'phase_data' => $phaseData
        ]);

        // Obtener PhaseManager a través del RoundManager
        $roundManager = $this->getRoundManager($match);
        $phaseManager = $roundManager->getTurnManager();

        if (!$phaseManager) {
            Log::warning("[Mockup] PhaseManager no encontrado");
            return;
        }

        // IMPORTANTE: Asignar el match al PhaseManager
        // Sin esto, no puede emitir eventos on_start
        $phaseManager->setMatch($match);

        // Avanzar a la siguiente fase
        $nextPhaseInfo = $phaseManager->nextPhase();

        Log::info("➡️  [Mockup] Avanzando a siguiente fase", [
            'phase_name' => $nextPhaseInfo['phase_name'],
            'phase_index' => $nextPhaseInfo['phase_index'],
            'duration' => $nextPhaseInfo['duration']
        ]);

        // Guardar RoundManager actualizado (que contiene el PhaseManager)
        $this->saveRoundManager($match, $roundManager);

        // Emitir evento de cambio de fase
        event(new \App\Events\Game\PhaseChangedEvent(
            match: $match,
            newPhase: $nextPhaseInfo['phase_name'],
            previousPhase: $phaseData['name'] ?? 'phase1',
            additionalData: [
                'phase_index' => $nextPhaseInfo['phase_index'],
                'duration' => $nextPhaseInfo['duration'],
                'phase_name' => $nextPhaseInfo['phase_name']
            ]
        ));
    }

    /**
     * Callback cuando expira la fase 2.
     *
     * Llama a nextPhase() y verifica cycle_completed para decidir si terminar la ronda.
     */
    public function handlePhase2Ended(GameMatch $match, array $phaseData): void
    {
        Log::info("🏁 [Mockup] FASE 2 FINALIZADA - Ejecutando callback", [
            'match_id' => $match->id,
            'phase_data' => $phaseData
        ]);

        // Obtener PhaseManager
        $roundManager = $this->getRoundManager($match);
        $phaseManager = $roundManager->getTurnManager();

        if (!$phaseManager) {
            Log::warning("[Mockup] PhaseManager no encontrado");
            return;
        }

        // Asignar el match al PhaseManager
        $phaseManager->setMatch($match);

        // SIEMPRE avanzar a la siguiente fase
        Log::info("➡️  [Mockup] Avanzando a siguiente fase", [
            'phase_name' => $phaseData['name'],
            'match_id' => $match->id
        ]);

        $nextPhaseInfo = $phaseManager->nextPhase();

        // Guardar RoundManager actualizado
        $this->saveRoundManager($match, $roundManager);

        // Verificar si completó el ciclo de fases
        if ($nextPhaseInfo['cycle_completed']) {
            Log::info("✅ [Mockup] Ciclo de fases completado - Finalizando ronda", [
                'match_id' => $match->id,
                'current_round' => $roundManager->getCurrentRound(),
                'next_phase' => $nextPhaseInfo['phase_name']
            ]);

            // Finalizar ronda actual
            // Esto emitirá RoundEndedEvent con countdown 3s automáticamente
            $this->endCurrentRound($match);

            Log::info("🎉 [Mockup] Ronda finalizada - El sistema manejará el countdown y siguiente ronda", [
                'match_id' => $match->id
            ]);
        } else {
            // Si no completó el ciclo (teóricamente no debería pasar en mockup con 2 fases)
            Log::info("➡️  [Mockup] Avanzando a siguiente fase sin finalizar ronda", [
                'from' => $phaseData['name'],
                'to' => $nextPhaseInfo['phase_name'],
                'cycle_completed' => false
            ]);

            // Emitir evento de cambio de fase
            event(new \App\Events\Game\PhaseChangedEvent(
                match: $match,
                newPhase: $nextPhaseInfo['phase_name'],
                previousPhase: $phaseData['name'],
                additionalData: [
                    'phase_index' => $nextPhaseInfo['phase_index'],
                    'duration' => $nextPhaseInfo['duration'],
                    'phase_name' => $nextPhaseInfo['phase_name']
                ]
            ));
        }
    }

    // ========================================================================
    // NOTAS SOBRE EVENTOS DE FASE
    // ========================================================================

    /**
     * EVENTOS CUSTOM DE FASE:
     *
     * MockupGame usa Phase1EndedEvent como EJEMPLO de evento custom.
     * PhaseManager lo emite automáticamente cuando:
     * - El timer de phase1 expira
     * - config.json define: phases[0].custom_event = "App\\Events\\Mockup\\Phase1EndedEvent"
     *
     * VENTAJAS de este approach event-driven:
     * - PhaseManager emite el evento directamente (new CustomEvent($match, $phaseData))
     * - No necesita callbacks ni instancias del engine en memoria
     * - Mejor desacoplamiento y consistencia
     * - El evento se broadcast al frontend automáticamente
     *
     * Si necesitas lógica específica cuando termina una fase, puedes:
     * 1. Crear un Listener que escuche el evento custom
     * 2. Ejecutar la lógica en el listener
     * 3. Registrarlo en EventServiceProvider
     */
}
