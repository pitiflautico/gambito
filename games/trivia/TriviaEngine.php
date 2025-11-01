<?php

namespace Games\Trivia;

use App\Contracts\BaseGameEngine;
use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

class TriviaEngine extends BaseGameEngine
{
    // ========================================================================
    // MÉTODOS PÚBLICOS
    // ========================================================================

    public function initialize(GameMatch $match): void
    {
        Log::info('[Trivia] Initializing', ['match_id' => $match->id]);

        $gameConfig = $this->getGameConfig();

        $match->game_state = [
            '_config' => [
                'game' => 'trivia',
                'initialized_at' => now()->toDateTimeString(),
                'timing' => $gameConfig['timing'] ?? null,
                'modules' => $gameConfig['modules'] ?? [],
            ],
            'phase' => 'starting',
            'actions' => [],
            '_meta' => [
                'used_questions' => [], // Track used question texts to avoid repeats
            ],
        ];
        $match->save();

        // UI base
        $this->setUI($match, 'general.show_header', true);
        $this->setUI($match, 'general.show_scores', true);
        $this->setUI($match, 'general.animations.confetti', false);
        $this->setUI($match, 'phases.results.show_winner', false);
        $this->setUI($match, 'transitions.phase_changing', false);
        $this->setUI($match, 'transitions.round_ending', false);
        $match->save();

        // Inicializar módulos según config
        $this->initializeModules($match, [
            'round_system' => [
                'total_rounds' => $gameConfig['modules']['round_system']['total_rounds'] ?? 5,
            ],
            'scoring_system' => [
                'calculator' => new TriviaScoreCalculator(),
            ],
        ]);

        // NUEVA ARQUITECTURA UNIFICADA: PlayerManager con scoreCalculator
        // PlayerManager es la fuente de verdad de scores individuales
        $scoreCalculator = new TriviaScoreCalculator();
        
        // PlayerManager: restaurar o crear y asignar roles desde config
        if (isset($match->game_state['player_system'])) {
            $playerManager = \App\Services\Modules\PlayerSystem\PlayerManager::fromArray(
                $match->game_state,
                $scoreCalculator // ← NUEVO: pasar calculator
            );
        } else {
            $playerIds = $match->players->pluck('id')->toArray();
            $rolesConfig = $match->game_state['_config']['modules']['roles_system']['roles'] ?? [];
            $availableRoles = array_map(fn($role) => $role['name'], $rolesConfig);
            $playerManager = new \App\Services\Modules\PlayerSystem\PlayerManager(
                playerIds: $playerIds,
                scoreCalculator: $scoreCalculator, // ← NUEVO: pasar calculator
                config: ['available_roles' => $availableRoles]
            );
            $playerManager->autoAssignRolesFromConfig($rolesConfig, shuffle: true);
        }

        $this->savePlayerManager($match, $playerManager);
        Log::info('[Trivia] Initialized');
    }

    public function finalize(GameMatch $match): array
    {
        Log::info('[Trivia] Finalizing', ['match_id' => $match->id]);
        
        // NUEVA ARQUITECTURA: Obtener scores finales desde PlayerManager (fuente de verdad)
        $playerManager = $this->getPlayerManager($match, new TriviaScoreCalculator());
        $scores = $playerManager->getScores();
        arsort($scores);
        $ranking = [];
        $pos = 1;
        foreach ($scores as $playerId => $score) {
            $ranking[] = ['position' => $pos++, 'player_id' => $playerId, 'score' => $score];
        }
        $winner = !empty($ranking) ? $ranking[0]['player_id'] : null;

        $match->game_state = array_merge($match->game_state, [
            'phase' => 'finished',
            'finished_at' => now()->toDateTimeString(),
            'final_scores' => $scores,
            'ranking' => $ranking,
            'winner' => $winner,
        ]);
        $match->save();

        event(new \App\Events\Game\GameEndedEvent(
            match: $match,
            winner: $winner,
            ranking: $ranking,
            scores: $scores
        ));

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
    // MÉTODOS PROTEGIDOS (flujo del juego)
    // ========================================================================

    protected function onGameStart(GameMatch $match): void
    {
        Log::info('[Trivia] onGameStart');
        $match->game_state = array_merge($match->game_state, ['phase' => 'playing']);
        $match->save();
        
        // Note: UI setup will happen in onRoundStarting() which is called by handleNewRound()
        $this->handleNewRound($match, advanceRound: false);
    }

    /**
     * Hook llamado ANTES de emitir RoundStartedEvent.
     * Aquí preparamos los datos de la ronda (pregunta, opciones) para que estén
     * disponibles cuando PhaseManager emita Phase1StartedEvent.
     */
    protected function onRoundStarting(GameMatch $match): void
    {
        Log::info('[Trivia] onRoundStarting hook called');
        
        // Llamar a startNewRound() que establece el UI con la pregunta
        $this->startNewRound($match);
        
        // Log UI state snapshot after startNewRound
        Log::info('[Trivia] UI state after onRoundStarting/startNewRound', [
            'question_text' => $match->game_state['_ui']['phases']['question']['text'] ?? 'MISSING',
            'options_count' => count($match->game_state['_ui']['phases']['answering']['options'] ?? []),
            'correct_option' => $match->game_state['_ui']['phases']['answering']['correct_option'] ?? 'MISSING',
        ]);
    }

    protected function startNewRound(GameMatch $match): void
    {
        Log::info('[Trivia] startNewRound hook');
        // NOTA: PlayerManager.reset() ya se llama automáticamente en handleNewRound()
        // Aquí solo hacemos la lógica específica del juego: cargar pregunta, establecer UI, etc.
        
        // Limpiar acciones del game_state
        $gameState = $match->game_state;
        $gameState['actions'] = [];
        $gameState['phase'] = 'playing';
        $match->game_state = $gameState;
        $match->save();

        // Seleccionar pregunta desde assets según configuración (shuffle/categoría)
        $selectionMode = $match->game_state['_config']['customizableSettings']['question_selection']['default'] ?? 'shuffle';
        $category = $match->game_state['_config']['customizableSettings']['question_category']['default'] ?? 'easy';

        [$question, $options, $correctIndex] = $this->selectQuestion($match, $selectionMode, $category);
        Log::info('[Trivia] Selected question in startNewRound', [
            'selection_mode' => $selectionMode,
            'category' => $category,
            'question' => $question,
            'options_count' => count($options),
            'correct_index' => $correctIndex,
        ]);

        $this->setUI($match, 'phases.question.text', $question);
        $this->setUI($match, 'phases.answering.options', $options);
        $this->setUI($match, 'phases.answering.correct_option', $correctIndex);
        
        // Log UI state after setUI
        $match->save(); // Save immediately after setting UI
        Log::info('[Trivia] UI state after startNewRound setUI', [
            'question_text' => $match->game_state['_ui']['phases']['question']['text'] ?? 'MISSING',
            'options_count' => count($match->game_state['_ui']['phases']['answering']['options'] ?? []),
            'correct_option' => $match->game_state['_ui']['phases']['answering']['correct_option'] ?? 'MISSING',
        ]);
        // Guardar timestamp de inicio de fase para bonus de velocidad
        $this->setUI($match, 'phases.question.started_at', now()->timestamp);
        // Persistir duración de la fase (desde config)
        $phaseConfig = $match->game_state['_config']['modules']['phase_system']['phases'][0] ?? ['duration' => 25];
        $this->setUI($match, 'phases.question.duration', $phaseConfig['duration'] ?? 25);
        $match->save();
    }

    protected function processRoundAction(GameMatch $match, Player $player, array $data): array
    {
        $type = $data['action'] ?? 'default';
        $playerManager = $this->getPlayerManager($match);

        if ($playerManager->isPlayerLocked($player->id)) {
            return ['success' => false, 'message' => 'Ya respondiste esta ronda', 'force_end' => false];
        }

        // Validación server-side de respuesta
        if ($type === 'answer') {
            $optionIndex = $data['option_index'] ?? null;
            $correctIndex = $match->game_state['_ui']['phases']['answering']['correct_option'] ?? null;

            if ($optionIndex === null || $correctIndex === null) {
                return ['success' => false, 'message' => 'Respuesta inválida', 'force_end' => false];
            }

            if ((int)$optionIndex === (int)$correctIndex) {
                // Correcta: puntuar y finalizar ronda
                // NUEVA ARQUITECTURA: Usar PlayerManager como fuente de verdad
                $playerManager = $this->getPlayerManager($match, new TriviaScoreCalculator());
                $points = $this->calculateSpeedPoints($match, basePoints: 10);
                $playerManager->awardPoints($player->id, 'correct_answer', ['points' => $points], $match);
                $this->savePlayerManager($match, $playerManager);
                // savePlayerManager() sincroniza automáticamente a scoring_system para backward compatibility

                $state = $match->game_state;
                $state['actions'][$player->id] = 'correct_answer';
                $match->game_state = $state;
                $match->save();

                $this->setUI($match, 'general.animations.confetti', true);
                $match->save();

                // Finalizar ronda inmediatamente
                $this->endCurrentRound($match);

                return [
                    'success' => true,
                    'player_id' => $player->id,
                    'data' => ['action' => 'correct_answer', 'points_awarded' => $points],
                    'force_end' => true,
                    'end_reason' => 'correct_answer',
                ];
            }

            // Incorrecta: bloquear y comprobar todos bloqueados
            $playerManager->lockPlayer($player->id, $match, $player);
            $this->savePlayerManager($match, $playerManager);

            $state = $match->game_state;
            $state['actions'][$player->id] = 'wrong_answer';
            $match->game_state = $state;
            $match->save();

            $responders = $playerManager->getPlayersWithPersistentRole('player');
            if (empty($responders)) {
                $responders = $playerManager->getPlayerIds();
            }
            $locked = $playerManager->getLockedPlayers();
            $lockedResponders = array_intersect($responders, $locked);
            $allRespondersLocked = count($lockedResponders) === count($responders);

            if ($allRespondersLocked) {
                // Finalizar ronda al estar todos bloqueados
                $this->endCurrentRound($match);
                return [
                    'success' => true,
                    'player_id' => $player->id,
                    'data' => ['action' => 'wrong_answer'],
                    'force_end' => true,
                    'end_reason' => 'all_players_locked',
                ];
            }

            return [
                'success' => true,
                'player_id' => $player->id,
                'data' => ['action' => 'wrong_answer'],
                'force_end' => false,
            ];
        }

        if ($type === 'correct_answer') {
            $scoreManager = $this->getScoreManager($match);
            $scoreManager->awardPoints($player->id, 'correct_answer', ['points' => 10]);
            $this->saveScoreManager($match, $scoreManager);

            $state = $match->game_state;
            $state['actions'][$player->id] = 'correct_answer';
            $match->game_state = $state;
            $match->save();

            $this->setUI($match, 'general.animations.confetti', true);
            $this->setUI($match, 'phases.results.show_winner', true);
            $match->save();

            return [
                'success' => true,
                'player_id' => $player->id,
                'data' => ['action' => 'correct_answer', 'points_awarded' => 10],
                'force_end' => true,
                'end_reason' => 'correct_answer',
            ];
        }

        if ($type === 'wrong_answer') {
            $playerManager->lockPlayer($player->id, $match, $player);
            $this->savePlayerManager($match, $playerManager);

            $state = $match->game_state;
            $state['actions'][$player->id] = 'wrong_answer';
            $match->game_state = $state;
            $match->save();

            // si todos los jugadores están bloqueados, forzar fin
            $responders = $playerManager->getPlayersWithPersistentRole('player');
            if (empty($responders)) {
                $responders = $playerManager->getPlayerIds();
            }
            $locked = $playerManager->getLockedPlayers();
            $lockedResponders = array_intersect($responders, $locked);
            $allRespondersLocked = count($lockedResponders) === count($responders);

            if ($allRespondersLocked) {
                // Finalizar ronda al estar todos bloqueados
                $this->endCurrentRound($match);
                return [
                    'success' => true,
                    'player_id' => $player->id,
                    'data' => ['action' => 'wrong_answer'],
                    'force_end' => true,
                    'end_reason' => 'all_players_locked',
                ];
            }

            return [
                'success' => true,
                'player_id' => $player->id,
                'data' => ['action' => 'wrong_answer'],
                'force_end' => false,
            ];
        }

        // default
        $value = $data['value'] ?? 'noop';
        $state = $match->game_state;
        $state['actions'][$player->id] = $value;
        $match->game_state = $state;
        $match->save();
        return ['success' => true, 'player_id' => $player->id, 'data' => ['action' => $value], 'force_end' => false];
    }

    public function endCurrentRound(GameMatch $match): void
    {
        $allActions = $match->game_state['actions'] ?? [];
        $scoreManager = $this->getScoreManager($match);
        $scores = $scoreManager->getScores();
        $results = ['actions' => $allActions];
        $this->completeRound($match, $results, $scores);
    }

    protected function getAllPlayerResults(GameMatch $match): array
    {
        return $match->game_state['actions'] ?? [];
    }

    protected function getRoundResults(GameMatch $match): array
    {
        $allActions = $match->game_state['actions'] ?? [];
        $scoreManager = $this->getScoreManager($match);
        $scores = $scoreManager->getScores();
        return ['actions' => $allActions, 'scores' => $scores];
    }

    // ========================================================================
    // Callbacks de fase
    // ========================================================================

    public function handleQuestionEnded(GameMatch $match, array $phaseData): void
    {
        $roundManager = $this->getRoundManager($match);
        $phaseManager = $roundManager->getTurnManager();
        if (!$phaseManager) { return; }
        $phaseManager->setMatch($match);
        $next = $phaseManager->nextPhase();
        $this->saveRoundManager($match, $roundManager);

        // Con fase única, al completar el ciclo finalizamos la ronda
        if (($next['cycle_completed'] ?? false) === true) {
            $this->endCurrentRound($match);
            return;
        }

        event(new \App\Events\Game\PhaseChangedEvent(
            match: $match,
            newPhase: $next['phase_name'],
            previousPhase: $phaseData['name'] ?? 'question',
            additionalData: [
                'phase_index' => $next['phase_index'],
                'duration' => $next['duration'],
                'phase_name' => $next['phase_name']
            ]
        ));
    }

    public function handleAnsweringEnded(GameMatch $match, array $phaseData): void
    {
        $roundManager = $this->getRoundManager($match);
        $phaseManager = $roundManager->getTurnManager();
        if (!$phaseManager) { return; }
        $phaseManager->setMatch($match);
        $next = $phaseManager->nextPhase();
        $this->saveRoundManager($match, $roundManager);

        if ($next['cycle_completed'] ?? false) {
            $this->endCurrentRound($match);
        } else {
            event(new \App\Events\Game\PhaseChangedEvent(
                match: $match,
                newPhase: $next['phase_name'],
                previousPhase: $phaseData['name'] ?? 'answering',
                additionalData: [
                    'phase_index' => $next['phase_index'],
                    'duration' => $next['duration'],
                    'phase_name' => $next['phase_name']
                ]
            ));
        }
    }

    public function handleResultsEnded(GameMatch $match, array $phaseData): void
    {
        $roundManager = $this->getRoundManager($match);
        $phaseManager = $roundManager->getTurnManager();
        if (!$phaseManager) { return; }
        $phaseManager->setMatch($match);
        $next = $phaseManager->nextPhase();
        $this->saveRoundManager($match, $roundManager);
        if ($next['cycle_completed'] ?? false) {
            $this->endCurrentRound($match);
        }
    }

    // ========================================================================
    // Utils
    // ========================================================================

    protected function getGameConfig(): array
    {
        $configPath = base_path('games/trivia/config.json');
        if (!file_exists($configPath)) {
            return $this->getDefaultConfig();
        }
        return json_decode(file_get_contents($configPath), true);
    }

    private function getDefaultConfig(): array
    {
        return [
            'timing' => [
                'round_ended' => [
                    'type' => 'countdown',
                    'delay' => 3,
                    'auto_next' => true,
                ],
            ],
            'modules' => [
                'scoring_system' => ['enabled' => true],
                'round_system' => ['enabled' => true],
                'timer_system' => ['enabled' => true],
            ],
        ];
    }

    /**
     * Seleccionar una pregunta desde assets/questions.json según modo y categoría.
     */
    private function selectQuestion(GameMatch $match, string $selectionMode, string $category): array
    {
        $path = base_path('games/trivia/assets/questions.json');
        if (!file_exists($path)) {
            return [
                '¿Capital de Francia?',
                ['Madrid', 'París', 'Roma', 'Berlín'],
                1
            ];
        }
        $data = json_decode(file_get_contents($path), true);

        $pool = [];
        if ($selectionMode === 'category') {
            $pool = $data[$category] ?? [];
        } else { // shuffle por defecto
            foreach (['easy','medium','hard'] as $cat) {
                if (isset($data[$cat]) && is_array($data[$cat])) {
                    $pool = array_merge($pool, $data[$cat]);
                }
            }
        }

        if (empty($pool)) {
            return [
                '¿Capital de Francia?',
                ['Madrid', 'París', 'Roma', 'Berlín'],
                1
            ];
        }

        // Get used questions from game_state metadata
        $usedQuestions = $match->game_state['_meta']['used_questions'] ?? [];
        
        // Filter out already used questions
        $availableQuestions = [];
        foreach ($pool as $idx => $item) {
            $questionText = $item['q'] ?? '';
            $questionHash = md5($questionText); // Use hash for comparison
            if (!in_array($questionHash, $usedQuestions)) {
                $availableQuestions[$idx] = $item;
            }
        }

        // If all questions have been used, reset and start over
        if (empty($availableQuestions)) {
            Log::info('[Trivia] All questions used, resetting used_questions list');
            $availableQuestions = $pool;
            $usedQuestions = [];
        }

        // Select random question from available pool
        $availableIndices = array_keys($availableQuestions);
        $randomIdx = $availableIndices[array_rand($availableIndices)];
        $item = $availableQuestions[$randomIdx];
        
        $question = $item['q'] ?? 'Pregunta';
        $options = $item['options'] ?? [];
        $answer = $item['answer'] ?? 0;
        
        // Track this question as used
        $questionHash = md5($question);
        $usedQuestions[] = $questionHash;
        
        // Update game_state with used questions
        $gameState = $match->game_state;
        if (!isset($gameState['_meta'])) {
            $gameState['_meta'] = [];
        }
        $gameState['_meta']['used_questions'] = $usedQuestions;
        $match->game_state = $gameState;
        $match->save();
        
        Log::info('[Trivia] Selected question', [
            'question_preview' => substr($question, 0, 50),
            'total_used' => count($usedQuestions),
            'available_count' => count($availableQuestions),
        ]);
        
        return [$question, $options, $answer];
    }

    /**
     * Calcular puntos con bonus por velocidad en función del tiempo restante.
     */
    private function calculateSpeedPoints(GameMatch $match, int $basePoints): int
    {
        $enabled = $match->game_state['_config']['customizableSettings']['speed_scoring_enabled']['default'] ?? true;
        $maxBonus = (int)($match->game_state['_config']['customizableSettings']['speed_scoring_max_bonus']['default'] ?? 10);
        if (!$enabled) {
            return $basePoints;
        }

        $startedAt = $match->game_state['_ui']['phases']['question']['started_at'] ?? null;
        $duration = $match->game_state['_ui']['phases']['question']['duration'] ?? null;

        if ($startedAt === null || $duration === null) {
            return $basePoints;
        }

        $elapsed = now()->timestamp - (int)$startedAt;
        if ($elapsed < 0) { $elapsed = 0; }
        $remaining = max(0, ((int)$duration) - $elapsed);
        $fraction = $duration > 0 ? ($remaining / $duration) : 0;
        $bonus = (int)round($maxBonus * $fraction);
        return max(0, $basePoints + $bonus);
    }
}


