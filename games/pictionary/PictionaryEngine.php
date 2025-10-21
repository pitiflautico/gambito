<?php

namespace Games\Pictionary;

use App\Contracts\GameEngineInterface;
use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Support\Facades\Log;
use Games\Pictionary\Events\PlayerAnsweredEvent;
use Games\Pictionary\Events\PlayerEliminatedEvent;
use Games\Pictionary\Events\GameStateUpdatedEvent;
use Games\Pictionary\Events\RoundEndedEvent;
use Games\Pictionary\Events\TurnChangedEvent;
use App\Services\Modules\TurnSystem\TurnManager;

/**
 * Motor del juego Pictionary.
 *
 * Implementación MODULAR del juego Pictionary donde un jugador dibuja
 * una palabra mientras los demás intentan adivinarla.
 *
 * Módulos utilizados:
 * - Turn System: Gestión de turnos y rondas
 * - Scoring System: Puntuación (TODO - Fase 4)
 * - Timer System: Temporizadores (TODO - Fase 4)
 * - Roles System: Roles drawer/guesser (TODO - Fase 4)
 */
class PictionaryEngine implements GameEngineInterface
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
        // TURN SYSTEM MODULE: Inicializar gestión de turnos
        // ========================================================================
        $turnManager = new TurnManager(
            playerIds: $playerIds,
            mode: $gameConfig['turnSystemConfig']['mode'], // Siempre 'sequential' para Pictionary
            totalRounds: $totalRounds,
            startingRound: 1
        );

        // Inicializar puntuaciones en 0
        $scores = [];
        foreach ($turnManager->getTurnOrder() as $playerId) {
            $scores[$playerId] = 0;
        }

        // Seleccionar primera palabra
        $firstWord = $this->selectRandomWordFromArray($words, $wordDifficulty);

        // Estado inicial del juego - comienza en ronda 1
        $match->game_state = array_merge([
            'phase' => 'playing',
            'current_drawer_id' => $turnManager->getCurrentPlayer(), // Primer jugador es el dibujante
            'current_word' => $firstWord,
            'current_word_difficulty' => $wordDifficulty,
            'game_is_paused' => false, // Se pausa cuando alguien pulsa "YO SÉ" (diferente de turn_system paused)
            'scores' => $scores,
            'words_available' => $words, // Palabras disponibles por dificultad
            'words_used' => [$firstWord], // Ya usamos la primera palabra
            'eliminated_this_round' => [],
            'pending_answer' => null, // {player_id, player_name, timestamp}
            'turn_duration' => $turnDuration, // Desde configuración
            'turn_started_at' => now()->toIso8601String(),
        ], $turnManager->toArray()); // Merge con estado del TurnManager

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

    /**
     * Procesar una acción de un jugador.
     *
     * Acciones soportadas:
     * - 'draw': Trazo en el canvas (Task 7.0 - WebSockets)
     * - 'answer': Jugador intenta responder
     * - 'confirm_answer': Dibujante confirma si respuesta es correcta
     *
     * @param GameMatch $match La partida actual
     * @param Player $player El jugador que realizó la acción
     * @param string $action El tipo de acción
     * @param array $data Datos adicionales de la acción
     * @return array Resultado de la acción
     */
    public function processAction(GameMatch $match, Player $player, string $action, array $data): array
    {
        Log::info("Processing action", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'action' => $action,
        ]);

        return match ($action) {
            'draw' => $this->handleDrawAction($match, $player, $data),
            'answer' => $this->handleAnswerAction($match, $player, $data),
            'confirm_answer' => $this->handleConfirmAnswer($match, $player, $data),
            default => ['success' => false, 'error' => 'Unknown action'],
        };
    }

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
        $turnManager = TurnManager::fromArray($gameState);

        if (!$turnManager->isGameComplete()) {
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
        $isDrawer = ($gameState['current_drawer_id'] === $player->id);
        $isEliminated = in_array($player->id, $gameState['eliminated_this_round']);

        // Calcular tiempo restante
        $timeRemaining = null;
        if ($gameState['turn_started_at'] && $gameState['phase'] === 'playing') {
            $turnStartedAt = new \DateTime($gameState['turn_started_at']);
            $now = new \DateTime();
            $secondsElapsed = $now->getTimestamp() - $turnStartedAt->getTimestamp();
            $maxTime = $gameState['turn_duration'] ?? 90;
            $timeRemaining = max(0, $maxTime - $secondsElapsed);
        }

        // ========================================================================
        // TURN SYSTEM MODULE: Obtener info del turno actual
        // ========================================================================
        $turnManager = TurnManager::fromArray($gameState);

        // Estado base para todos
        $state = [
            'phase' => $gameState['phase'],
            'round' => $turnManager->getCurrentRound(),
            'rounds_total' => $gameState['total_rounds'],
            'is_drawer' => $isDrawer,
            'is_eliminated' => $isEliminated,
            'current_drawer_id' => $gameState['current_drawer_id'],
            'is_paused' => $gameState['game_is_paused'] ?? false,
            'time_remaining' => $timeRemaining,
            'scores' => $gameState['scores'],
            'turn_order' => $turnManager->getTurnOrder(),
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
                $turnManager = TurnManager::fromArray($gameState);

                $gameState['phase'] = 'playing';
                $gameState['current_drawer_id'] = $turnManager->getCurrentPlayer();
                $gameState['current_word'] = $this->selectRandomWord($match, 'easy');
                $gameState['turn_started_at'] = now()->toDateTimeString();
                $gameState['eliminated_this_round'] = []; // Resetear eliminados al iniciar

                if ($gameState['current_word']) {
                    $gameState['words_used'][] = $gameState['current_word'];
                }
                break;

            case 'playing':
                // Terminar turno, ir a scoring
                $gameState['phase'] = 'scoring';
                break;

            case 'scoring':
                // ========================================================================
                // TURN SYSTEM MODULE: Verificar si el juego terminó
                // ========================================================================
                $turnManager = TurnManager::fromArray($gameState);

                // Verificar si estamos en la última ronda Y es el último turno
                $isLastRound = ($turnManager->getCurrentRound() >= $gameState['total_rounds']);
                $isLastTurn = ($turnManager->getCurrentTurnIndex() >= ($turnManager->getPlayerCount() - 1));
                $gameEnded = $isLastRound && $isLastTurn;

                if ($gameEnded) {
                    // Juego terminado - NO avanzar más turnos
                    $gameState['phase'] = 'results';

                    Log::info("Game finished - all rounds completed", [
                        'match_id' => $match->id,
                        'final_round' => $turnManager->getCurrentRound(),
                        'final_turn' => $turnManager->getCurrentTurnIndex(),
                        'total_rounds' => $gameState['total_rounds'],
                        'total_players' => $turnManager->getPlayerCount()
                    ]);

                    // Encontrar ganador
                    $scores = $gameState['scores'];
                    arsort($scores); // Ordenar por puntuación descendente
                    $winnerId = array_key_first($scores);
                    $winner = Player::find($winnerId);
                    $winnerName = $winner ? $winner->name : "Player {$winnerId}";

                    // Crear ranking
                    $ranking = [];
                    foreach ($scores as $playerId => $score) {
                        $player = Player::find($playerId);
                        $ranking[] = [
                            'player_id' => $playerId,
                            'player_name' => $player ? $player->name : "Player {$playerId}",
                            'score' => $score
                        ];
                    }

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

        Log::warning("Player disconnected", [
            'match_id' => $match->id,
            'player_id' => $player->id,
            'is_drawer' => ($gameState['current_drawer_id'] === $player->id)
        ]);

        // Si es el dibujante quien se desconectó
        if ($gameState['current_drawer_id'] === $player->id) {
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
        $scores = $gameState['scores'];

        // Ordenar jugadores por puntuación (descendente)
        arsort($scores);

        // Crear ranking con información de jugadores
        $ranking = [];
        $position = 1;

        foreach ($scores as $playerId => $score) {
            $player = Player::find($playerId);

            if ($player) {
                $ranking[] = [
                    'position' => $position++,
                    'player_id' => $playerId,
                    'player_name' => $player->name,
                    'score' => $score,
                ];
            }
        }

        // Determinar ganador
        $winner = !empty($ranking) ? $ranking[0] : null;

        // ========================================================================
        // TURN SYSTEM MODULE: Obtener info final del juego
        // ========================================================================
        $turnManager = TurnManager::fromArray($gameState);

        // Generar estadísticas
        $statistics = [
            'total_rounds' => $turnManager->getCurrentRound(),
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

        // Validaciones
        if ($player->id === $gameState['current_drawer_id']) {
            return ['success' => false, 'error' => 'El dibujante no puede pulsar "YO SÉ"'];
        }

        if (in_array($player->id, $gameState['eliminated_this_round'])) {
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

        // Validar que el jugador es el dibujante
        if ($player->id !== $gameState['current_drawer_id']) {
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

            // Calcular segundos transcurridos
            $turnStartedAt = new \DateTime($gameState['turn_started_at']);
            $now = new \DateTime();
            $secondsElapsed = $now->getTimestamp() - $turnStartedAt->getTimestamp();

            // Calcular puntos según el tiempo
            $guesserPoints = $this->calculatePointsByTime($secondsElapsed, $gameState);
            $drawerPoints = $this->getDrawerPointsByTime($secondsElapsed, $gameState);

            // Otorgar puntos
            $gameState['scores'][$guesserPlayerId] += $guesserPoints;
            $gameState['scores'][$player->id] += $drawerPoints;

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
            $turnManager = TurnManager::fromArray($gameState);

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
                $turnManager->getCurrentRound(),
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
                'guesser_points' => $guesserPoints,
                'drawer_points' => $drawerPoints,
                'seconds_elapsed' => $secondsElapsed,
                'message' => "¡{$guesserPlayerName} acertó!",
                'phase' => 'scoring'
            ];
        } else {
            // ❌ RESPUESTA INCORRECTA: Eliminar jugador y continuar

            // Eliminar jugador de esta ronda
            $gameState['eliminated_this_round'][] = $guesserPlayerId;

            // Limpiar respuesta pendiente
            $gameState['pending_answer'] = null;

            // REANUDAR el dibujo
            $gameState['game_is_paused'] = false;

            $match->game_state = $gameState;
            $match->save();

            Log::info("Answer confirmed as INCORRECT - Game continues", [
                'match_id' => $match->id,
                'guesser_id' => $guesserPlayerId,
                'guesser_name' => $guesserPlayerName,
                'eliminated_count' => count($gameState['eliminated_this_round'])
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
        $turnManager = TurnManager::fromArray($gameState);
        $turnInfo = $turnManager->nextTurn();

        // Seleccionar nueva palabra
        $newWord = $this->selectRandomWord($match, 'random');

        // Actualizar estado específico de Pictionary
        $gameState['current_drawer_id'] = $turnInfo['player_id'];
        $gameState['current_word'] = $newWord;
        $gameState['eliminated_this_round'] = [];
        $gameState['pending_answer'] = null;
        $gameState['turn_started_at'] = now()->toDateTimeString();

        // Marcar palabra como usada
        if ($newWord) {
            $gameState['words_used'][] = $newWord;
        }

        // Actualizar estado del Turn System en game_state
        $gameState = array_merge($gameState, $turnManager->toArray());

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
            $turnInfo['round'],
            $turnInfo['turn_index'],
            $gameState['scores']
        ));

        Log::info("Advanced to next turn", [
            'match_id' => $match->id,
            'round' => $turnInfo['round'],
            'turn_index' => $turnInfo['turn_index'],
            'drawer_id' => $turnInfo['player_id'],
            'word' => $newWord,
            'round_completed' => $turnInfo['round_completed']
        ]);
    }

    /**
     * Calcular puntos para el adivinador según el tiempo transcurrido.
     *
     * Sistema de puntuación:
     * - 0-30s (rápido): 150 puntos
     * - 31-60s (normal): 100 puntos
     * - 61-90s (lento): 50 puntos (puntuación mínima)
     * - >90s: 0 puntos (tiempo agotado, no debería llegar aquí)
     *
     * @param int $secondsElapsed Segundos transcurridos desde el inicio del turno
     * @param array $gameState Estado actual del juego
     * @return int Puntos otorgados
     */
    private function calculatePointsByTime(int $secondsElapsed, array $gameState): int
    {
        $maxTime = $gameState['turn_duration'] ?? 90;
        $normalThreshold = $maxTime * 0.33; // 30s si maxTime=90
        $slowThreshold = $maxTime * 0.67; // 60s si maxTime=90

        if ($secondsElapsed <= $normalThreshold) {
            // Rápido: 150 puntos
            return 150;
        } elseif ($secondsElapsed <= $slowThreshold) {
            // Normal: 100 puntos
            return 100;
        } elseif ($secondsElapsed <= $maxTime) {
            // Lento: 50 puntos (puntuación mínima)
            return 50;
        } else {
            // Tiempo agotado: 0 puntos
            return 0;
        }
    }

    /**
     * Calcular puntos para el dibujante según el tiempo transcurrido.
     *
     * El dibujante recibe puntos similares pero menores que el adivinador:
     * - 0-30s (rápido): 75 puntos
     * - 31-60s (normal): 50 puntos
     * - 61-90s (lento): 25 puntos (puntuación mínima)
     * - >90s: 0 puntos
     *
     * @param int $secondsElapsed Segundos transcurridos
     * @param array $gameState Estado actual del juego
     * @return int Puntos otorgados
     */
    private function getDrawerPointsByTime(int $secondsElapsed, array $gameState): int
    {
        $maxTime = $gameState['turn_duration'] ?? 90;
        $normalThreshold = $maxTime * 0.33; // 30s
        $slowThreshold = $maxTime * 0.67; // 60s

        if ($secondsElapsed <= $normalThreshold) {
            // Rápido: 75 puntos
            return 75;
        } elseif ($secondsElapsed <= $slowThreshold) {
            // Normal: 50 puntos
            return 50;
        } elseif ($secondsElapsed <= $maxTime) {
            // Lento: 25 puntos (puntuación mínima)
            return 25;
        } else {
            // Tiempo agotado: 0 puntos
            return 0;
        }
    }
}
