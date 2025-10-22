<?php

namespace Games\Pictionary;

use App\Contracts\BaseGameEngine;
use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Support\Facades\Log;
use Games\Pictionary\Events\PlayerAnsweredEvent;
use Games\Pictionary\Events\PlayerEliminatedEvent;
use Games\Pictionary\Events\GameStateUpdatedEvent;
use Games\Pictionary\Events\RoundEndedEvent;
use Games\Pictionary\Events\TurnChangedEvent;
use App\Services\Modules\TurnSystem\TurnManager;
use App\Services\Modules\RoundSystem\RoundManager;
use App\Services\Modules\ScoringSystem\ScoreManager;
use App\Services\Modules\TimerSystem\TimerService;
use App\Services\Modules\RolesSystem\RoleManager;
use Games\Pictionary\PictionaryScoreCalculator;

/**
 * Motor del juego Pictionary.
 *
 * Implementación MODULAR del juego Pictionary donde un jugador dibuja
 * una palabra mientras los demás intentan adivinarla.
 *
 * Módulos utilizados:
 * - Turn System: Gestión de turnos y rondas ✅
 * - Scoring System: Puntuación basada en tiempo ✅
 * - Timer System: Temporizadores por turno ✅
 * - Roles System: Roles drawer/guesser ✅
 */
class PictionaryEngine extends BaseGameEngine
{
    /**
     * Obtener la configuración del juego desde config.json.
     *
     * @return array Configuración del juego
     */
    private function getGameConfig(): array
    {
        $configPath = base_path('games/pictionary/config.json');
        return json_decode(file_get_contents($configPath), true);
    }

    /**
     * Inicializar el juego cuando comienza una partida.
     *
     * Setup inicial:
     * - Cargar palabras desde words.json
     * - Asignar orden de turnos aleatorio
     * - Inicializar puntuaciones en 0
     * - Establecer ronda 1, turno 1
     *
     * @param GameMatch $match La partida que se está iniciando
     * @return void
     */
    public function initialize(GameMatch $match): void
    {
        Log::info("Initializing Pictionary game", ['match_id' => $match->id]);

        // Obtener todos los jugadores de la partida
        $players = $match->players()->where('is_connected', true)->get();

        if ($players->count() < 3) {
            Log::error("Not enough players to start Pictionary", [
                'match_id' => $match->id,
                'player_count' => $players->count()
            ]);
            throw new \Exception('Se requieren al menos 3 jugadores para jugar Pictionary');
        }

        // Cargar palabras desde words.json
        $wordsPath = base_path('games/pictionary/assets/words.json');
        $words = json_decode(file_get_contents($wordsPath), true);

        // Obtener IDs de jugadores
        $playerIds = $players->pluck('id')->toArray();

        // ========================================================================
        // CONFIGURACIÓN CUSTOMIZABLE: Leer settings de la sala o usar defaults
        // ========================================================================
        $roomSettings = $match->room->settings ?? [];
        $gameConfig = $this->getGameConfig();

        // Determinar número de rondas
        $roundsMode = $roomSettings['rounds_mode'] ?? $gameConfig['customizableSettings']['rounds_mode']['default'];
        if ($roundsMode === 'auto') {
            $totalRounds = count($playerIds); // 1 ronda por jugador
        } else {
            $totalRounds = $roomSettings['rounds_total'] ?? $gameConfig['customizableSettings']['rounds_total']['default'];
        }

        // Obtener duración del turno
        $turnDuration = $roomSettings['turn_duration'] ?? $gameConfig['customizableSettings']['turn_duration']['default'];

        // Obtener dificultad de palabras
        $wordDifficulty = $roomSettings['word_difficulty'] ?? $gameConfig['customizableSettings']['word_difficulty']['default'];

        // ========================================================================
        // ROUND/TURN SYSTEM MODULES: Inicializar gestión de rondas y turnos
        // ========================================================================
        $turnManager = new TurnManager(
            playerIds: $playerIds,
            mode: $gameConfig['turnSystemConfig']['mode'] // Siempre 'sequential' para Pictionary
        );

        $roundManager = new RoundManager(
            turnManager: $turnManager,
            totalRounds: $totalRounds,
            currentRound: 1
        );

        // ========================================================================
        // SCORING SYSTEM MODULE: Inicializar gestión de puntuaciones
        // ========================================================================
        $scoreCalculator = new PictionaryScoreCalculator();
        $scoreManager = new ScoreManager(
            playerIds: $playerIds,
            calculator: $scoreCalculator,
            trackHistory: false // No necesitamos historial en Pictionary
        );

        // ========================================================================
        // TIMER SYSTEM MODULE: Inicializar timer del turno
        // ========================================================================
        $timerService = new TimerService();
        $timerService->startTimer('turn_timer', $turnDuration);

        // ========================================================================
        // ROLES SYSTEM MODULE: Inicializar roles (drawer/guesser)
        // ========================================================================
        $roleManager = new RoleManager(
            availableRoles: ['drawer', 'guesser'],
            allowMultipleRoles: false
        );

        // Asignar primer drawer (según turnos)
        $firstDrawerId = $roundManager->getCurrentPlayer();
        $roleManager->assignRole($firstDrawerId, 'drawer');

        // Asignar guessers (todos los demás)
        foreach ($playerIds as $playerId) {
            if ($playerId !== $firstDrawerId) {
                $roleManager->assignRole($playerId, 'guesser');
            }
        }

        // Seleccionar primera palabra
        $firstWord = $this->selectRandomWordFromArray($words, $wordDifficulty);

        // Estado inicial del juego - comienza en ronda 1
        $match->game_state = array_merge([
            'phase' => 'playing',
            'current_drawer_id' => $firstDrawerId, // Mantener para compatibilidad (computed desde RoleManager)
            'current_word' => $firstWord,
            'current_word_difficulty' => $wordDifficulty,
            'game_is_paused' => false, // Se pausa cuando alguien pulsa "YO SÉ" (diferente de turn_system paused)
            'words_available' => $words, // Palabras disponibles por dificultad
            'words_used' => [$firstWord], // Ya usamos la primera palabra
            'pending_answer' => null, // {player_id, player_name, timestamp}
            'turn_duration' => $turnDuration, // Guardamos duración para referencia (usado en cálculos de puntos)
        ], $roundManager->toArray(), $scoreManager->toArray(), $timerService->toArray(), $roleManager->toArray()); // Merge con módulos

        $match->save();

        Log::info("Pictionary initialized successfully", [
            'match_id' => $match->id,
            'players' => count($playerIds),
            'words_loaded' => [
                'easy' => count($words['easy'] ?? []),
                'medium' => count($words['medium'] ?? []),
                'hard' => count($words['hard'] ?? []),
            ]
        ]);
    }

    // processAction() ahora es heredado de BaseGameEngine
    // que llama a processRoundAction() con el action en $data

    /**
     * Verificar si hay un ganador.
     *
     * Condición de victoria: El jugador con más puntos después de X rondas.
     *
     * @param GameMatch $match La partida actual
     * @return Player|null El jugador ganador, o null si aún no hay ganador
     */
    public function checkWinCondition(GameMatch $match): ?Player
    {
        $gameState = $match->game_state;

        // ========================================================================
        // TURN SYSTEM MODULE: Verificar si el juego ha terminado
        // ========================================================================
        $roundManager = RoundManager::fromArray($gameState);

        if (!$roundManager->isGameComplete()) {
            return null; // Aún no terminan todas las rondas
        }

        // Encontrar jugador con mayor puntuación
        $scores = $gameState['scores'];

        if (empty($scores)) {
            return null;
        }

        // Obtener el ID del jugador con más puntos
        $winnerId = array_search(max($scores), $scores);

        if (!$winnerId) {
            return null;
        }

        // Retornar el modelo Player
        return Player::find($winnerId);
    }

    /**
     * Obtener el estado actual del juego para un jugador específico.
     *
     * Información visible según rol:
     * - Dibujante: Ve la palabra secreta, canvas, tiempo restante
     * - Adivinadores: Ven canvas, jugadores, NO ven la palabra
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador que solicita el estado
     * @return array Estado del juego visible para ese jugador
     */
    public function getGameStateForPlayer(GameMatch $match, Player $player): array
    {
        $gameState = $match->game_state;

        // ========================================================================
        // TURN SYSTEM MODULE: Verificar si jugador está eliminado temporalmente
        // ========================================================================
        $roundManager = RoundManager::fromArray($gameState);
        $isEliminated = $roundManager->isTemporarilyEliminated($player->id);

        // ========================================================================
        // ROLES SYSTEM MODULE: Verificar rol del jugador
        // ========================================================================
        $roleManager = RoleManager::fromArray($gameState);
        $isDrawer = $roleManager->hasRole($player->id, 'drawer');
        $currentDrawers = $roleManager->getPlayersWithRole('drawer');
        $currentDrawerId = !empty($currentDrawers) ? $currentDrawers[0] : null;

        // ========================================================================
        // TIMER SYSTEM MODULE: Obtener tiempo restante del turno
        // ========================================================================
        $timerService = TimerService::fromArray($gameState);
        $timeRemaining = null;
        if ($timerService->hasTimer('turn_timer') && $gameState['phase'] === 'playing') {
            $timeRemaining = $timerService->getRemainingTime('turn_timer');
        }

        // ========================================================================
        // TURN SYSTEM MODULE: Obtener info del turno actual
        // ========================================================================
        $roundManager = RoundManager::fromArray($gameState);

        // Estado base para todos
        $state = [
            'phase' => $gameState['phase'],
            'round' => $roundManager->getCurrentRound(),
            'rounds_total' => $gameState['total_rounds'],
            'is_drawer' => $isDrawer,
            'is_eliminated' => $isEliminated,
            'current_drawer_id' => $currentDrawerId,
            'is_paused' => $gameState['game_is_paused'] ?? false,
            'time_remaining' => $timeRemaining,
            'scores' => $gameState['scores'],
            'turn_order' => $roundManager->getTurnOrder(),
        ];

        // Solo el dibujante ve la palabra y las respuestas pendientes
        if ($isDrawer) {
            $state['word'] = $gameState['current_word'];
            $state['pending_answer'] = $gameState['pending_answer'];
        }

        // Los eliminados ven que están eliminados
        if ($isEliminated) {
            $state['eliminated_message'] = 'Has sido eliminado de esta ronda. Espera al siguiente turno.';
        }

        return $state;
    }

    /**
     * Avanzar a la siguiente fase/ronda del juego.
     *
     * Fases:
     * 1. lobby -> drawing (al iniciar)
     * 2. drawing -> scoring (al terminar turno)
     * 3. scoring -> drawing (siguiente turno) o -> results (fin de partida)
     *
     * @param GameMatch $match La partida actual
     * @return void
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
            case 'lobby':
                // ========================================================================
                // TURN SYSTEM MODULE: Iniciar el juego (primera vez)
                // ========================================================================
                $roundManager = RoundManager::fromArray($gameState);

                // ========================================================================
                // TIMER SYSTEM MODULE: Reiniciar timer del turno
                // ========================================================================
                $timerService = TimerService::fromArray($gameState);
                $timerService->restartTimer('turn_timer');

                // ========================================================================
                // ROLES SYSTEM MODULE: No es necesario rotar (ya se asignó en initialize)
                // ========================================================================
                $roleManager = RoleManager::fromArray($gameState);

                // ========================================================================
                // TURN SYSTEM MODULE: Auto-limpieza de temporales al iniciar ronda
                // ========================================================================
                // Nota: TurnManager ya limpió temporarilyEliminated cuando nextTurn() completó ronda

                $gameState['phase'] = 'playing';
                $gameState['current_word'] = $this->selectRandomWord($match, 'easy');

                if ($gameState['current_word']) {
                    $gameState['words_used'][] = $gameState['current_word'];
                }

                // Actualizar estado de los módulos en game_state
                $gameState = array_merge($gameState, $timerService->toArray(), $roleManager->toArray());
                break;

            case 'playing':
                // Terminar turno, ir a scoring
                $gameState['phase'] = 'scoring';
                break;

            case 'scoring':
                // ========================================================================
                // TURN SYSTEM MODULE: Verificar si el juego terminó
                // ========================================================================
                $roundManager = RoundManager::fromArray($gameState);

                // Verificar si estamos en la última ronda Y es el último turno
                $isLastRound = ($roundManager->getCurrentRound() >= $gameState['total_rounds']);
                $isLastTurn = ($roundManager->getCurrentTurnIndex() >= ($roundManager->getPlayerCount() - 1));
                $gameEnded = $isLastRound && $isLastTurn;

                if ($gameEnded) {
                    // Juego terminado - NO avanzar más turnos
                    $gameState['phase'] = 'results';

                    Log::info("Game finished - all rounds completed", [
                        'match_id' => $match->id,
                        'final_round' => $roundManager->getCurrentRound(),
                        'final_turn' => $roundManager->getCurrentTurnIndex(),
                        'total_rounds' => $gameState['total_rounds'],
                        'total_players' => $roundManager->getPlayerCount()
                    ]);

                    // ================================================================
                    // SCORING SYSTEM MODULE: Encontrar ganador y generar ranking
                    // ================================================================
                    $scoreCalculator = new PictionaryScoreCalculator();
                    $scoreManager = ScoreManager::fromArray(
                        playerIds: array_keys($gameState['scores']),
                        data: $gameState,
                        calculator: $scoreCalculator
                    );

                    // Encontrar ganador
                    $winnerData = $scoreManager->getWinner();
                    $winnerId = $winnerData ? $winnerData['player_id'] : null;
                    $winner = $winnerId ? Player::find($winnerId) : null;
                    $winnerName = $winner ? $winner->name : "Player {$winnerId}";

                    // Crear ranking
                    $rawRanking = $scoreManager->getRanking();
                    $ranking = [];
                    foreach ($rawRanking as $entry) {
                        $player = Player::find($entry['player_id']);
                        $ranking[] = [
                            'player_id' => $entry['player_id'],
                            'player_name' => $player ? $player->name : "Player {$entry['player_id']}",
                            'score' => $entry['score']
                        ];
                    }

                    $scores = $gameState['scores'];

                    // Emitir evento de juego terminado
                    $roomCode = $match->room->code ?? 'UNKNOWN';
                    event(new \Games\Pictionary\Events\GameFinishedEvent(
                        $roomCode,
                        $winnerId,
                        $winnerName,
                        $scores,
                        $ranking
                    ));
                } else {
                    // Continuar jugando - avanzar al siguiente turno
                    $this->nextTurn($match);
                    $gameState = $match->game_state; // Recargar después de nextTurn
                    $gameState['phase'] = 'playing';
                }
                break;

            case 'results':
                // Juego terminado, no hay más fases
                Log::info("Game already in results phase");
                return;
        }

        $match->game_state = $gameState;
        $match->save();

        Log::info("Phase advanced", [
            'match_id' => $match->id,
            'new_phase' => $gameState['phase']
        ]);
    }

    /**
     * Manejar la desconexión de un jugador.
     *
     * Estrategia:
     * - Si es el dibujante: Pausar turno, esperar 2 min, si no vuelve -> skip turno
     * - Si es adivinador: Marcar como desconectado, puede reconectar
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador que se desconectó
     * @return void
     */
    public function handlePlayerDisconnect(GameMatch $match, Player $player): void
    {
        $gameState = $match->game_state;

        // ========================================================================
        // ROLES SYSTEM MODULE: Verificar si es el drawer
        // ========================================================================
        $roleManager = RoleManager::fromArray($gameState);
        $isDrawer = $roleManager->hasRole($player->id, 'drawer');

        Log::warning("Player disconnected", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'is_drawer' => $isDrawer
        ]);

        // Si es el dibujante quien se desconectó
        if ($isDrawer) {
            // Pausar el juego
            $gameState['game_is_paused'] = true;
            $gameState['disconnect_pause_started_at'] = now()->toDateTimeString();

            $match->game_state = $gameState;
            $match->save();

            Log::warning("Drawer disconnected - Game paused. Waiting 2 minutes for reconnection.");

            // TODO Task 7.0: Broadcast a todos que el juego está pausado
            // TODO: Implementar Job que después de 2 min sin reconexión, skip turno
        } else {
            // Es un adivinador, marcar como desconectado pero el juego continúa
            Log::info("Guesser disconnected - Game continues");
            // El Player model ya tiene is_connected = false
        }
    }

    /**
     * Manejar la reconexión de un jugador.
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador que se reconectó
     * @return void
     */
    public function handlePlayerReconnect(GameMatch $match, Player $player): void
    {
        Log::info("Player reconnected", [
            'match_id' => $match->id,
            'player_id' => $player->id,
        ]);

        // TODO: Sincronizar estado actual en Task 7.0 - WebSockets
    }

    /**
     * Finalizar la partida.
     *
     * Calcula puntuaciones finales, determina ganador, genera estadísticas.
     *
     * @param GameMatch $match La partida que está finalizando
     * @return array Datos finales de la partida
     */
    public function finalize(GameMatch $match): array
    {
        Log::info("Finalizing Pictionary game", ['match_id' => $match->id]);

        $gameState = $match->game_state;

        // ========================================================================
        // SCORING SYSTEM MODULE: Generar ranking final
        // ========================================================================
        $scoreCalculator = new PictionaryScoreCalculator();
        $scoreManager = ScoreManager::fromArray(
            playerIds: array_keys($gameState['scores']),
            data: $gameState,
            calculator: $scoreCalculator
        );

        // Obtener ranking ordenado
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

        // Determinar ganador
        $winner = !empty($ranking) ? $ranking[0] : null;

        // ========================================================================
        // TURN SYSTEM MODULE: Obtener info final del juego
        // ========================================================================
        $roundManager = RoundManager::fromArray($gameState);

        // Generar estadísticas
        $statistics = [
            'total_rounds' => $roundManager->getCurrentRound(),
            'total_words_used' => count($gameState['words_used']),
            'players_count' => count($scores),
            'highest_score' => $winner ? $winner['score'] : 0,
            'average_score' => !empty($scores) ? round(array_sum($scores) / count($scores), 2) : 0,
        ];

        Log::info("Pictionary game finalized", [
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
     * Manejar acción de dibujar en el canvas.
     */
    private function handleDrawAction(GameMatch $match, Player $player, array $data): array
    {
        // TODO: Implementar en Task 7.0 - WebSockets
        // - Validar que el jugador es el dibujante
        // - Broadcast del trazo a todos los espectadores

        return ['success' => true];
    }

    /**
     * Manejar cuando un jugador pulsa el botón "YO SÉ".
     *
     * El jugador indica que sabe la respuesta, se PAUSA el dibujo,
     * y el dibujante debe confirmar si es correcta o no (el jugador
     * dice la palabra en VOZ ALTA en la reunión presencial).
     */
    private function handleAnswerAction(GameMatch $match, Player $player, array $data): array
    {
        $gameState = $match->game_state;

        // ========================================================================
        // ROLES SYSTEM MODULE: Verificar que no sea el drawer
        // ========================================================================
        $roleManager = RoleManager::fromArray($gameState);
        $isDrawer = $roleManager->hasRole($player->id, 'drawer');

        // TURN SYSTEM MODULE: Verificar eliminación temporal
        $roundManager = RoundManager::fromArray($gameState);

        // Validaciones
        if ($isDrawer) {
            return ['success' => false, 'error' => 'El dibujante no puede pulsar "YO SÉ"'];
        }

        if ($roundManager->isTemporarilyEliminated($player->id)) {
            return ['success' => false, 'error' => 'Ya fuiste eliminado en esta ronda'];
        }

        if ($gameState['phase'] !== 'playing') {
            return ['success' => false, 'error' => 'No puedes responder en esta fase'];
        }

        if ($gameState['game_is_paused'] ?? false) {
            return ['success' => false, 'error' => 'El juego está pausado esperando confirmación'];
        }

        // PAUSAR el dibujo para todos
        $gameState['game_is_paused'] = true;

        // Guardar quién pulsó "YO SÉ" para que el dibujante confirme
        $gameState['pending_answer'] = [
            'player_id' => $player->id,
            'player_name' => $player->name,
            'timestamp' => now()->toDateTimeString(),
        ];

        $match->game_state = $gameState;
        $match->save();

        Log::info("Player pressed 'YO SÉ' button - game paused", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'player_name' => $player->name
        ]);

        // Broadcast a todos los jugadores vía WebSocket
        event(new PlayerAnsweredEvent($match, $player));

        return [
            'success' => true,
            'paused' => true,
            'awaiting_confirmation' => true,
            'message' => 'Di la respuesta EN VOZ ALTA. Esperando confirmación del dibujante.'
        ];
    }

    /**
     * Manejar confirmación del dibujante (respuesta correcta/incorrecta).
     *
     * Si es correcta: Termina la ronda, otorga puntos a ambos.
     * Si es incorrecta: Elimina al jugador, REANUDA el dibujo.
     */
    private function handleConfirmAnswer(GameMatch $match, Player $player, array $data): array
    {
        $gameState = $match->game_state;
        $isCorrect = $data['is_correct'] ?? false;

        // ========================================================================
        // ROLES SYSTEM MODULE: Verificar que sea el drawer
        // ========================================================================
        $roleManager = RoleManager::fromArray($gameState);
        $isDrawer = $roleManager->hasRole($player->id, 'drawer');

        // Validar que el jugador es el dibujante
        if (!$isDrawer) {
            return ['success' => false, 'error' => 'Solo el dibujante puede confirmar respuestas'];
        }

        // Validar que hay una respuesta pendiente
        if (!$gameState['pending_answer']) {
            return ['success' => false, 'error' => 'No hay respuesta pendiente'];
        }

        $pendingAnswer = $gameState['pending_answer'];
        $guesserPlayerId = $pendingAnswer['player_id'];
        $guesserPlayerName = $pendingAnswer['player_name'];

        if ($isCorrect) {
            // ✅ RESPUESTA CORRECTA: Termina la ronda

            // ========================================================================
            // TIMER SYSTEM MODULE: Obtener tiempo transcurrido del timer
            // ========================================================================
            $timerService = TimerService::fromArray($gameState);
            $secondsElapsed = $timerService->getElapsedTime('turn_timer');

            // ========================================================================
            // SCORING SYSTEM MODULE: Otorgar puntos usando ScoreManager
            // ========================================================================
            $scoreCalculator = new PictionaryScoreCalculator();
            $scoreManager = ScoreManager::fromArray(
                playerIds: array_keys($gameState['scores']),
                data: $gameState,
                calculator: $scoreCalculator
            );

            // Otorgar puntos al adivinador
            $guesserPoints = $scoreManager->awardPoints($guesserPlayerId, 'correct_answer', [
                'seconds_elapsed' => $secondsElapsed,
                'turn_duration' => $gameState['turn_duration'],
            ]);

            // Otorgar puntos bonus al dibujante
            $drawerPoints = $scoreManager->awardPoints($player->id, 'drawer_bonus', [
                'seconds_elapsed' => $secondsElapsed,
                'turn_duration' => $gameState['turn_duration'],
            ]);

            // Actualizar scores en game_state
            $gameState['scores'] = $scoreManager->getScores();

            // Limpiar estado
            $gameState['pending_answer'] = null;
            $gameState['game_is_paused'] = false;

            // Cambiar a fase de scoring (fin de turno)
            $gameState['phase'] = 'scoring';

            $match->game_state = $gameState;
            $match->save();

            // ========================================================================
            // TURN SYSTEM MODULE: Obtener ronda actual para el evento
            // ========================================================================
            $roundManager = RoundManager::fromArray($gameState);

            Log::info("Answer confirmed as CORRECT - Round ended", [
                'match_id' => $match->id,
                'guesser_id' => $guesserPlayerId,
                'guesser_name' => $guesserPlayerName,
                'guesser_points' => $guesserPoints,
                'drawer_points' => $drawerPoints,
                'seconds_elapsed' => $secondsElapsed
            ]);

            // Obtener room code para el evento
            $roomCode = $match->room->code ?? 'UNKNOWN';

            // Broadcast fin de ronda con detalles de puntos
            event(new RoundEndedEvent(
                $roomCode,
                $roundManager->getCurrentRound(),
                $gameState['current_word'],
                $guesserPlayerId,
                $guesserPlayerName,
                $guesserPoints,
                $drawerPoints,
                $gameState['scores']
            ));

            // Broadcast actualización de estado (fase scoring, puntos actualizados)
            event(new GameStateUpdatedEvent($match, 'round_ended'));

            return [
                'success' => true,
                'correct' => true,
                'round_ended' => true,
                'should_end_turn' => true, // Señal para BaseGameEngine
                'delay_seconds' => 3, // Delay antes del siguiente turno
                'guesser_points' => $guesserPoints,
                'drawer_points' => $drawerPoints,
                'seconds_elapsed' => $secondsElapsed,
                'message' => "¡{$guesserPlayerName} acertó!",
                'phase' => 'scoring'
            ];
        } else {
            // ❌ RESPUESTA INCORRECTA: Eliminar jugador y verificar si quedan jugadores activos

            // TURN SYSTEM MODULE: Eliminar jugador temporalmente
            $roundManager = RoundManager::fromArray($gameState);
            $roleManager = RoleManager::fromArray($gameState);

            $roundManager->eliminatePlayer($guesserPlayerId, permanent: false);

            // Limpiar respuesta pendiente
            $gameState['pending_answer'] = null;

            // Verificar cuántos jugadores activos quedan (excluyendo al drawer)
            $drawerId = $roleManager->getPlayersWithRole('drawer')[0] ?? null;
            $activePlayers = $roundManager->getActivePlayers();

            // Filtrar el drawer de los jugadores activos (solo contar adivinos)
            $activeGuessers = array_filter($activePlayers, fn($id) => $id !== $drawerId);

            // Si NO quedan jugadores activos para adivinar → Terminar ronda sin ganador
            if (count($activeGuessers) === 0) {
                Log::info("All guessers eliminated - Round ended without winner", [
                    'match_id' => $match->id,
                    'last_guesser_id' => $guesserPlayerId,
                    'last_guesser_name' => $guesserPlayerName,
                ]);

                // Cambiar a fase de scoring (fin de turno sin ganador)
                $gameState['phase'] = 'scoring';
                $gameState['game_is_paused'] = false;

                // Actualizar game_state
                $gameState = array_merge($gameState, $roundManager->toArray());
                $match->game_state = $gameState;
                $match->save();

                // Obtener room code para el evento
                $roomCode = $match->room->code ?? 'UNKNOWN';

                // Broadcast fin de ronda sin ganador
                event(new RoundEndedEvent(
                    $roomCode,
                    $roundManager->getCurrentRound(),
                    $gameState['current_word'],
                    null, // No hay ganador
                    'Nadie',
                    0, // Sin puntos
                    0, // Sin puntos
                    $gameState['scores']
                ));

                // Broadcast actualización de estado
                event(new GameStateUpdatedEvent($match, 'round_ended_no_winner'));

                return [
                    'success' => true,
                    'correct' => false,
                    'round_ended' => true,
                    'all_eliminated' => true,
                    'message' => "Todos los jugadores fallaron. La ronda termina sin ganador.",
                    'phase' => 'scoring'
                ];
            }

            // Si SÍ quedan jugadores activos → Continuar el juego
            // REANUDAR el dibujo
            $gameState['game_is_paused'] = false;

            // Actualizar game_state con roundManager modificado
            $gameState = array_merge($gameState, $roundManager->toArray());
            $match->game_state = $gameState;
            $match->save();

            Log::info("Answer confirmed as INCORRECT - Game continues", [
                'match_id' => $match->id,
                'guesser_id' => $guesserPlayerId,
                'guesser_name' => $guesserPlayerName,
                'eliminated_count' => count($roundManager->getTemporarilyEliminated()),
                'active_guessers_remaining' => count($activeGuessers)
            ]);

            // Obtener el Player model para el evento
            $eliminatedPlayer = Player::find($guesserPlayerId);

            // Broadcast eliminación del jugador
            event(new PlayerEliminatedEvent($match, $eliminatedPlayer));

            // Broadcast actualización de estado (juego reanudado, lista de eliminados actualizada)
            event(new GameStateUpdatedEvent($match, 'player_eliminated'));

            return [
                'success' => true,
                'correct' => false,
                'round_continues' => true,
                'eliminated_player' => $guesserPlayerName,
                'active_guessers_remaining' => count($activeGuessers),
                'message' => "{$guesserPlayerName} falló. El juego continúa."
            ];
        }
    }

    // ========================================================================
    // MÉTODOS AUXILIARES
    // ========================================================================

    /**
     * Seleccionar una palabra aleatoria que no se haya usado.
     *
     * @param GameMatch $match
     * @param string $difficulty 'easy', 'medium', 'hard', o 'random'
     * @return string|null
     */
    private function selectRandomWord(GameMatch $match, string $difficulty = 'random'): ?string
    {
        $gameState = $match->game_state;
        $wordsAvailable = $gameState['words_available'];
        $wordsUsed = $gameState['words_used'];

        // Si es random, elegir dificultad aleatoria
        if ($difficulty === 'random') {
            $difficulties = ['easy', 'medium', 'hard'];
            $difficulty = $difficulties[array_rand($difficulties)];
        }

        // Obtener palabras de esa dificultad que no se hayan usado
        $availableWords = $wordsAvailable[$difficulty] ?? [];
        $availableWords = array_diff($availableWords, $wordsUsed);

        if (empty($availableWords)) {
            // Si se acabaron las palabras de esa dificultad, intentar con otra
            Log::warning("No more words available for difficulty: {$difficulty}");
            return null;
        }

        // Seleccionar palabra aleatoria
        $word = $availableWords[array_rand($availableWords)];

        return $word;
    }

    /**
     * Seleccionar una palabra aleatoria de un array de palabras (sin GameMatch).
     * Útil para inicialización.
     *
     * @param array $words Array de palabras por dificultad
     * @param string $difficulty 'easy', 'medium', 'hard', o 'random'
     * @return string|null
     */
    private function selectRandomWordFromArray(array $words, string $difficulty = 'mixed'): ?string
    {
        // Si es random o mixed, elegir dificultad aleatoria
        if ($difficulty === 'random' || $difficulty === 'mixed') {
            $difficulties = ['easy', 'medium', 'hard'];
            $difficulty = $difficulties[array_rand($difficulties)];
        }

        // Obtener palabras de esa dificultad
        $availableWords = $words[$difficulty] ?? [];

        if (empty($availableWords)) {
            Log::warning("No words available for difficulty: {$difficulty}");
            return null;
        }

        // Seleccionar palabra aleatoria
        $word = $availableWords[array_rand($availableWords)];

        return $word;
    }

    /**
     * Avanzar al siguiente turno.
     *
     * Usa el módulo Turn System para gestionar el avance de turnos.
     *
     * @param GameMatch $match
     * @return void
     */
    private function nextTurn(GameMatch $match): void
    {
        $gameState = $match->game_state;

        // ========================================================================
        // TURN SYSTEM MODULE: Restaurar y avanzar turno
        // ========================================================================
        $roundManager = RoundManager::fromArray($gameState);
        $turnInfo = $roundManager->nextTurn();

        // ========================================================================
        // TIMER SYSTEM MODULE: Reiniciar timer para el nuevo turno
        // ========================================================================
        $timerService = TimerService::fromArray($gameState);
        $timerService->restartTimer('turn_timer');

        // ========================================================================
        // ROLES SYSTEM MODULE: Rotar rol de drawer al siguiente jugador
        // ========================================================================
        $roleManager = RoleManager::fromArray($gameState);
        $newDrawerId = $roleManager->rotateRole('drawer', $roundManager->getTurnOrder());

        // Reasignar guessers (todos excepto el nuevo drawer)
        foreach ($roundManager->getTurnOrder() as $playerId) {
            if ($playerId !== $newDrawerId) {
                if (!$roleManager->hasRole($playerId, 'guesser')) {
                    $roleManager->assignRole($playerId, 'guesser');
                }
            } else {
                // Remover guesser del nuevo drawer si lo tenía
                $roleManager->removeRole($playerId, 'guesser');
            }
        }

        // Seleccionar nueva palabra
        $newWord = $this->selectRandomWord($match, 'random');

        // Actualizar estado específico de Pictionary
        $gameState['current_drawer_id'] = $newDrawerId; // Mantener para compatibilidad (computed desde RoleManager)
        $gameState['current_word'] = $newWord;
        $gameState['pending_answer'] = null;
        // Nota: Turn System limpia automáticamente temporarilyEliminated cuando completa ronda

        // Marcar palabra como usada
        if ($newWord) {
            $gameState['words_used'][] = $newWord;
        }

        // Actualizar estado de los módulos en game_state
        $gameState = array_merge($gameState, $roundManager->toArray(), $timerService->toArray(), $roleManager->toArray());

        $match->game_state = $gameState;
        $match->save();

        // Get room code and new drawer info for the event
        $roomCode = $match->room->code ?? 'UNKNOWN';
        $newDrawer = Player::find($turnInfo['player_id']);
        $newDrawerName = $newDrawer ? $newDrawer->name : "Player {$turnInfo['player_id']}";

        // Broadcast turn change event
        event(new TurnChangedEvent(
            $roomCode,
            $turnInfo['player_id'],
            $newDrawerName,
            $gameState['current_round'], // Desde RoundManager, no desde turnInfo
            $turnInfo['turn_index'],
            $gameState['scores']
        ));

        Log::info("Advanced to next turn", [
            'match_id' => $match->id,
            'round' => $gameState['current_round'], // Desde RoundManager
            'turn_index' => $turnInfo['turn_index'],
            'drawer_id' => $turnInfo['player_id'],
            'word' => $newWord,
            'cycle_completed' => $turnInfo['cycle_completed'] // Actualizado de round_completed
        ]);
    }

    // ============================================================================
    // MÉTODOS ABSTRACTOS DE BASEGAMEENGINE
    // ============================================================================

    /**
     * Procesar acción de jugador en modo secuencial.
     *
     * Para Pictionary, tenemos múltiples tipos de acciones:
     * - 'draw': Trazos en el canvas
     * - 'answer': Jugador presiona "YO SÉ"
     * - 'confirm_answer': Drawer confirma si es correcto/incorrecto
     *
     * @param GameMatch $match
     * @param Player $player
     * @param array $data
     * @return array
     */
    protected function processRoundAction(GameMatch $match, Player $player, array $data): array
    {
        // BaseGameEngine nos pasa el 'action' dentro de $data
        $action = $data['action'] ?? 'unknown';

        return match ($action) {
            'draw' => $this->handleDrawAction($match, $player, $data),
            'answer' => $this->handleAnswerAction($match, $player, $data),
            'confirm_answer' => $this->handleConfirmAnswer($match, $player, $data),
            default => ['success' => false, 'error' => 'Unknown action'],
        };
    }

    /**
     * Obtener resultados de jugadores.
     *
     * En modo secuencial (Pictionary) no aplica este concepto,
     * ya que solo un jugador actúa por turno.
     *
     * @param GameMatch $match
     * @return array
     */
    protected function getAllPlayerResults(GameMatch $match): array
    {
        // No aplica en modo secuencial
        return [];
    }

    /**
     * Iniciar nueva ronda/turno.
     *
     * Verifica si el juego terminó antes de avanzar.
     *
     * @param GameMatch $match
     * @return void
     */
    protected function startNewRound(GameMatch $match): void
    {
        $gameState = $match->game_state;
        $roundManager = RoundManager::fromArray($gameState);

        // Verificar si el juego terminó
        if ($roundManager->isGameComplete()) {
            $this->finalize($match);
            return;
        }

        // Continuar - avanzar al siguiente turno
        $this->nextTurn($match);

        // Actualizar fase a 'playing'
        $gameState = $match->fresh()->game_state;
        $gameState['phase'] = 'playing';
        $match->game_state = $gameState;
        $match->save();
    }

    /**
     * Finalizar turno actual.
     *
     * En Pictionary, esto ya se maneja en handleConfirmAnswer,
     * así que no necesitamos hacer nada adicional aquí.
     *
     * @param GameMatch $match
     * @return void
     */
    protected function endCurrentRound(GameMatch $match): void
    {
        // La lógica de fin de turno ya está en handleConfirmAnswer
        // que emite eventos y actualiza puntos
        // No necesitamos duplicarla aquí
    }

    // ============================================================================
    // MÉTODOS ANTIGUOS DE PUNTUACIÓN ELIMINADOS
    // ============================================================================
    // Los métodos calculatePointsByTime() y getDrawerPointsByTime() han sido
    // reemplazados por el módulo ScoreManager + PictionaryScoreCalculator
    // Ver: app/Services/Modules/ScoringSystem/
    // ============================================================================
}
