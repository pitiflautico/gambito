<?php

namespace Games\Trivia;

use App\Contracts\BaseGameEngine;
use App\Models\GameMatch;
use App\Models\Player;
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

        // Guardar configuración con preguntas
        $match->game_state = [
            '_config' => [
                'game' => 'trivia',
                'initialized_at' => now()->toDateTimeString(),
                'categories' => $questionsData['categories'],
            ],
            'phase' => 'waiting',
            'questions' => $questions,
            'current_question' => null,
        ];

        $match->save();

        // Inicializar módulos automáticamente desde config.json
        $this->initializeModules($match);

        Log::info("[Trivia] Questions loaded and shuffled", [
            'match_id' => $match->id,
            'total_questions' => count($questions)
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
     * Procesar acción de ronda (abstracto de BaseGameEngine).
     * Por ahora no hace nada.
     */
    protected function processRoundAction(GameMatch $match, Player $player, array $data): array
    {
        return ['success' => false, 'message' => 'No implementado aún'];
    }

    /**
     * Iniciar nueva ronda - Cargar siguiente pregunta.
     *
     * Este método se llama desde BaseGameEngine::handleNewRound()
     * DESPUÉS de que RoundManager haya avanzado la ronda y reseteado el timer.
     */
    protected function startNewRound(GameMatch $match): void
    {
        Log::info("[Trivia] Starting new round", ['match_id' => $match->id]);

        // 1. Desbloquear jugadores (via PlayerStateManager)
        $playerState = $this->getPlayerStateManager($match);
        $playerState->reset();  // ← Resetea locks, actions, states, etc.
        $this->savePlayerStateManager($match, $playerState);

        // 2. Cargar siguiente pregunta
        $question = $this->loadNextQuestion($match);

        Log::info("[Trivia] Question loaded and players unlocked", [
            'match_id' => $match->id,
            'question_id' => $question['id'],
            'question' => $question['question']
        ]);

        // Emitir evento específico de Trivia (opcional)
        // event(new QuestionStartedEvent($match, $question));
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
     * Finalizar ronda actual (abstracto de BaseGameEngine).
     * Por ahora no hace nada.
     */
    protected function endCurrentRound(GameMatch $match): void
    {
        Log::info("[Trivia] endCurrentRound called (not implemented yet)", ['match_id' => $match->id]);
    }

    /**
     * Obtener resultados de todos los jugadores (abstracto de BaseGameEngine).
     * Por ahora retorna array vacío.
     */
    protected function getAllPlayerResults(GameMatch $match): array
    {
        return [];
    }

    /**
     * Procesar acción de un jugador.
     * Por ahora no hace nada.
     */
    public function processAction(GameMatch $match, Player $player, string $action, array $data): array
    {
        return ['success' => false, 'message' => 'No implementado aún'];
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

    /**
     * Manejar desconexión de jugador.
     */
    public function handlePlayerDisconnect(GameMatch $match, Player $player): void
    {
        Log::info("[Trivia] Player disconnected", [
            'match_id' => $match->id,
            'player_id' => $player->id
        ]);
    }

    /**
     * Manejar reconexión de jugador.
     */
    public function handlePlayerReconnect(GameMatch $match, Player $player): void
    {
        Log::info("[Trivia] Player reconnected", [
            'match_id' => $match->id,
            'player_id' => $player->id
        ]);
    }

    /**
     * Finalizar el juego.
     */
    public function finalize(GameMatch $match): array
    {
        Log::info("[Trivia] Finalizing game", ['match_id' => $match->id]);

        return [
            'winner' => null,
            'ranking' => [],
            'statistics' => [],
        ];
    }
}
